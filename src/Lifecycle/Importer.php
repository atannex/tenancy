<?php

declare(strict_types=1);

namespace Atannex\Tenancy\Lifecycle;

use Atannex\Tenancy\Bootstrappers\FilesystemBootstrapper;
use Atannex\Tenancy\Contracts\Tenant;
use Atannex\Tenancy\Domains\Domains;
use Atannex\Tenancy\Enums\DomainType;
use Atannex\Tenancy\Enums\IsolationTier;
use Atannex\Tenancy\Enums\TenantStatus;
use Atannex\Tenancy\Tenancy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;
use ZipArchive;

/**
 * Restores an Exporter zip into a new tenant: provisions it (database and
 * migrations for dedicated tiers), inserts every row with the tenant column
 * pointing at the new tenant, restores settings, feature overrides and usage,
 * and copies the files back.
 *
 * Domains come back unverified, as alias claims the new tenant must prove
 * by DNS, so an import can never take over a host. Memberships name user
 * ids, which only mean the same people on the installation that exported
 * them; restore them with $members = true only there.
 *
 * The zip is checked before anything is written: entry count and total
 * uncompressed size (tenancy.lifecycle.import), file paths, and tables.
 * Tables are streamed line by line and files straight to the disk.
 */
final class Importer
{
    public function __construct(
        private readonly Tenancy $tenancy,
        private readonly Provisioner $provisioner,
        private readonly TenantTables $tables,
        private readonly TenantLifecycle $lifecycle,
        private readonly Purger $purger,
        private readonly Domains $domains,
    ) {}

    /** @param array<string, mixed> $attributes overrides for the new tenant (slug, name, isolation …) */
    public function import(string $path, array $attributes = [], bool $members = false): Tenant&Model
    {
        if (! class_exists(ZipArchive::class)) {
            throw new RuntimeException('Tenant imports need the zip extension.');
        }

        $zip = new ZipArchive;

        if ($zip->open($path) !== true) {
            throw new RuntimeException("Cannot open [{$path}].");
        }

        try {
            $this->assertWithinLimits($zip);
            $manifest = json_decode((string) $zip->getFromName('manifest.json'), true);

            if (! is_array($manifest) || ($manifest['format'] ?? null) !== Exporter::FORMAT) {
                throw new RuntimeException("[{$path}] is not a tenant export.");
            }

            if ((int) ($manifest['version'] ?? 1) > Exporter::VERSION) {
                throw new RuntimeException("[{$path}] was written by a newer version of atannex/tenancy.");
            }

            $this->assertSafeFiles($zip);
            $package = array_intersect(array_keys((array) ($manifest['package'] ?? [])), Exporter::PACKAGE_TABLES);
            $members || $package = array_diff($package, ['tenant_memberships']);

            $source = (array) $manifest['tenant'];
            $tenant = $this->provisioner->create($attributes + [
                'name' => $source['name'],
                'slug' => $source['slug'],
                'isolation' => IsolationTier::from((string) ($source['isolation'] ?? 'shared')),
                'plan' => $source['plan'] ?? null,
            ]);

            if ($tenant->tenantStatus() !== TenantStatus::Active) {
                throw new RuntimeException("Provisioning the imported tenant failed; it is [{$tenant->tenantStatus()->value}].");
            }

            try {
                $this->restore($zip, $tenant, array_keys((array) ($manifest['tables'] ?? [])), array_values($package));
                $this->restoreDomains($tenant, (array) ($manifest['domains'] ?? []));
            } catch (Throwable $e) {
                $this->lifecycle->archive($tenant, 0);
                $this->purger->purge($tenant->refresh());

                throw $e;
            }
        } finally {
            $zip->close();
        }

        return $tenant;
    }

    /** Refuse zip bombs: too many entries, or too much once uncompressed. */
    private function assertWithinLimits(ZipArchive $zip): void
    {
        $maxEntries = (int) config('tenancy.lifecycle.import.max_entries', 100_000);
        $maxBytes = (int) config('tenancy.lifecycle.import.max_bytes', 2 * 1024 ** 3);

        if ($zip->numFiles > $maxEntries) {
            throw new RuntimeException("The export has {$zip->numFiles} entries; the limit is {$maxEntries} (tenancy.lifecycle.import.max_entries).");
        }

        $bytes = 0;

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $bytes += (int) ($zip->statIndex($i)['size'] ?? 0);

            if ($bytes > $maxBytes) {
                throw new RuntimeException("The export unpacks to more than {$maxBytes} bytes (tenancy.lifecycle.import.max_bytes).");
            }
        }
    }

    /** Files may only land inside the new tenant's folder on the tenancy disks. */
    private function assertSafeFiles(ZipArchive $zip): void
    {
        $disks = (array) config('tenancy.filesystem.disks', []);

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);

            if (! str_starts_with($name, 'files/')) {
                continue;
            }

            $parts = explode('/', substr($name, 6));
            $disk = array_shift($parts);
            $unsafe = $parts === [] || in_array('', $parts, true) || in_array('..', $parts, true) || in_array('.', $parts, true) || str_contains($name, '\\');

            if ($unsafe || ! in_array($disk, $disks, true) || ! config("filesystems.disks.{$disk}")) {
                throw new RuntimeException("Import refuses file [{$name}].");
            }
        }
    }

    /**
     * @param  list<string>  $tables
     * @param  list<string>  $package
     */
    private function restore(ZipArchive $zip, Tenant&Model $tenant, array $tables, array $package): void
    {
        $this->tenancy->central(function () use ($zip, $tenant, $tables, $package): void {
            // Only the new tenant's own tables, each with its own tenant column.
            // Listed before connecting: for() connects again, purging that connection.
            $allowed = $this->tables->for($tenant);
            $connection = $this->tables->connection($tenant);
            $default = (string) config('tenancy.database.tenant_column', 'tenant_id');
            $shared = $tenant->isolationTier() === IsolationTier::Shared;

            foreach ($tables as $table) {
                if (! array_key_exists($table, $allowed)) {
                    throw new RuntimeException("Import refuses table [{$table}]: it is not tenant data.");
                }
            }

            $connection->transaction(function () use ($zip, $tenant, $tables, $connection, $allowed, $default, $shared): void {
                foreach ($tables as $table) {
                    $column = $allowed[$table] ?? $default;

                    Rows::write($connection, $table, $this->lines($zip, "tables/{$table}.jsonl", function (array $row) use ($tenant, $column): array {
                        array_key_exists($column, $row) && $row[$column] = $tenant->getTenantKey();

                        return $row;
                    }), $shared);
                }
            });

            $this->restorePackage($zip, $tenant, $package);

            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = (string) $zip->getNameIndex($i);

                if (preg_match('#^files/([^/]+)/(.+)$#', $name, $m)) {
                    $stream = $zip->getStream($name) ?: throw new RuntimeException("Cannot read [{$name}] from the export.");
                    Storage::disk($m[1])->writeStream(FilesystemBootstrapper::suffix($tenant).'/'.$m[2], $stream);
                    is_resource($stream) && fclose($stream);
                }
            }
        });
    }

    /**
     * Settings, feature overrides, usage (and memberships when asked), with
     * fresh primary keys: these central tables hold every tenant's rows.
     *
     * @param  list<string>  $package
     */
    private function restorePackage(ZipArchive $zip, Tenant $tenant, array $package): void
    {
        $central = DB::connection($this->tenancy->centralConnection());

        $central->transaction(function () use ($zip, $tenant, $package, $central): void {
            foreach ($package as $table) {
                if (! $central->getSchemaBuilder()->hasTable($table)) {
                    continue;
                }

                $columns = array_flip($central->getSchemaBuilder()->getColumnListing($table));
                $ulid = $table === 'tenant_memberships';

                // The provisioning steps may already have written some (the owner's membership, default settings).
                $central->table($table)->where('tenant_id', $tenant->getTenantKey())->delete();

                foreach ($this->chunks($this->lines($zip, "package/{$table}.jsonl", function (array $row) use ($tenant, $columns, $ulid): array {
                    $row = array_intersect_key($row, $columns);
                    unset($row['id']);
                    $ulid && $row['id'] = strtolower((string) Str::ulid());
                    $row['tenant_id'] = $tenant->getTenantKey();

                    return $row;
                })) as $chunk) {
                    $central->table($table)->insert($chunk);
                }
            }
        });
    }

    /** @param list<array<string, mixed>> $domains */
    private function restoreDomains(Tenant&Model $tenant, array $domains): void
    {
        if (! method_exists($tenant, 'domains')) {
            return;
        }

        $taken = $tenant->domains()->pluck('host')->all();

        foreach ($domains as $domain) {
            $host = (string) ($domain['host'] ?? '');

            // Subdomains follow the slug; the new tenant already has its own.
            if ($host === '' || in_array($host, $taken, true) || ($domain['type'] ?? null) === DomainType::Subdomain->value) {
                continue;
            }

            try {
                $this->domains->add($tenant, $host, DomainType::Alias);
            } catch (\InvalidArgumentException) {
                // Verified by another tenant, or no longer a valid tenant host here: leave it out.
            }
        }
    }

    /**
     * Decoded JSON lines of one entry, read as a stream.
     *
     * @param  callable(array<string, mixed>): array<string, mixed>  $map
     * @return \Generator<int, array<string, mixed>>
     */
    private function lines(ZipArchive $zip, string $entry, callable $map): \Generator
    {
        $stream = $zip->getStream($entry);

        if ($stream === false) {
            return;
        }

        try {
            while (($line = fgets($stream)) !== false) {
                if (trim($line) !== '') {
                    yield $map(json_decode($line, true, flags: JSON_THROW_ON_ERROR));
                }
            }
        } finally {
            fclose($stream);
        }
    }

    /**
     * @param  iterable<array<string, mixed>>  $rows
     * @return \Generator<int, list<array<string, mixed>>>
     */
    private function chunks(iterable $rows): \Generator
    {
        $chunk = [];

        foreach ($rows as $row) {
            $chunk[] = $row;

            if (count($chunk) >= Rows::CHUNK) {
                yield $chunk;
                $chunk = [];
            }
        }

        if ($chunk !== []) {
            yield $chunk;
        }
    }
}
