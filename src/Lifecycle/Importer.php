<?php

declare(strict_types=1);

namespace Atannex\Tenancy\Lifecycle;

use Atannex\Tenancy\Bootstrappers\FilesystemBootstrapper;
use Atannex\Tenancy\Contracts\Tenant;
use Atannex\Tenancy\Enums\IsolationTier;
use Atannex\Tenancy\Enums\TenantStatus;
use Atannex\Tenancy\Tenancy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;
use ZipArchive;

/**
 * Restores an Exporter zip into a new tenant: provisions it (database and
 * migrations for dedicated tiers), inserts every row with the tenant column
 * pointing at the new tenant, and copies the files back. Domains are not
 * imported (hosts are unique); add them afterwards.
 */
final class Importer
{
    public function __construct(
        private readonly Tenancy $tenancy,
        private readonly Provisioner $provisioner,
        private readonly TenantTables $tables,
        private readonly TenantLifecycle $lifecycle,
        private readonly Purger $purger,
    ) {}

    /** @param array<string, mixed> $attributes overrides for the new tenant (slug, name, isolation …) */
    public function import(string $path, array $attributes = []): Tenant&Model
    {
        $zip = new ZipArchive;

        if ($zip->open($path) !== true) {
            throw new RuntimeException("Cannot open [{$path}].");
        }

        $manifest = json_decode((string) $zip->getFromName('manifest.json'), true);

        if (($manifest['format'] ?? null) !== Exporter::FORMAT) {
            throw new RuntimeException("[{$path}] is not a tenant export.");
        }

        $this->assertSafeFiles($zip);
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
            $this->restore($zip, $tenant, array_keys((array) ($manifest['tables'] ?? [])));
        } catch (Throwable $e) {
            $this->lifecycle->archive($tenant, 0);
            $this->purger->purge($tenant->refresh());

            throw $e;
        } finally {
            $zip->close();
        }

        return $tenant;
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

    /** @param list<string> $tables */
    private function restore(ZipArchive $zip, Tenant&Model $tenant, array $tables): void
    {
        $this->tenancy->central(function () use ($zip, $tenant, $tables): void {
            $connection = $this->tables->connection($tenant);
            // Only the new tenant's own tables, each with its own tenant column.
            $allowed = $this->tables->for($tenant);
            $default = (string) config('tenancy.database.tenant_column', 'tenant_id');

            foreach ($tables as $table) {
                if (! array_key_exists($table, $allowed)) {
                    throw new RuntimeException("Import refuses table [{$table}]: it is not tenant data.");
                }
            }

            $connection->transaction(function () use ($zip, $tenant, $tables, $connection, $allowed, $default): void {
                foreach ($tables as $table) {
                    $column = $allowed[$table] ?? $default;
                    $lines = array_filter(explode("\n", (string) $zip->getFromName("tables/{$table}.jsonl")));
                    $rows = array_map(function (string $line) use ($tenant, $column): array {
                        $row = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
                        array_key_exists($column, $row) && $row[$column] = $tenant->getTenantKey();

                        return $row;
                    }, $lines);

                    foreach (array_chunk($rows, 500) as $chunk) {
                        $connection->table($table)->insert($chunk);
                    }
                }
            });

            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = (string) $zip->getNameIndex($i);

                if (preg_match('#^files/([^/]+)/(.+)$#', $name, $m)) {
                    Storage::disk($m[1])->put(FilesystemBootstrapper::suffix($tenant).'/'.$m[2], (string) $zip->getFromIndex($i));
                }
            }
        });
    }
}
