<?php

declare(strict_types=1);

namespace Atannex\Tenancy\Lifecycle;

use Atannex\Tenancy\Bootstrappers\FilesystemBootstrapper;
use Atannex\Tenancy\Contracts\Tenant;
use Atannex\Tenancy\Tenancy;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;
use ZipArchive;

/**
 * One zip with everything a tenant owns: manifest.json, one JSON-lines file
 * per table (tables/{table}.jsonl), the package's own per-tenant records
 * (package/{table}.jsonl: settings, feature overrides, usage, memberships)
 * and its files (files/{disk}/…). For GDPR requests and for leaving the
 * platform; Importer reads it back.
 *
 * Rows are read in chunks and files are streamed, so memory stays flat for
 * big tenants. Invitation and impersonation tokens, database credentials
 * and maintenance secrets are never exported.
 */
final class Exporter
{
    public const string FORMAT = 'atannex-tenancy-export';

    public const int VERSION = 2;

    /** Central package tables holding per-tenant records, exported under package/. */
    public const array PACKAGE_TABLES = ['tenant_settings', 'tenant_feature_overrides', 'tenant_usage', 'tenant_memberships'];

    public function __construct(private readonly Tenancy $tenancy, private readonly TenantTables $tables) {}

    public function export(Tenant&Model $tenant, ?string $path = null): string
    {
        if (! class_exists(ZipArchive::class)) {
            throw new RuntimeException('Tenant exports need the zip extension.');
        }

        $path ??= storage_path('app/tenancy-exports/'.$tenant->getTenantSlug().'-'.now()->format('Ymd-His').'.zip');
        is_dir(dirname($path)) || mkdir(dirname($path), 0755, true);

        $zip = new ZipArchive;

        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException("Cannot write [{$path}].");
        }

        // ZipArchive reads added files on close(), so temp files live until then.
        $temp = [];

        try {
            $manifest = $this->tenancy->central(function () use ($tenant, $zip, &$temp): array {
                $connection = $this->tables->connection($tenant);
                $counts = [];

                foreach ($this->tables->for($tenant) as $table => $column) {
                    $counts[$table] = $this->addTable($zip, "tables/{$table}.jsonl", $connection, $table, $column, $tenant, $temp);
                }

                $central = DB::connection($this->tenancy->centralConnection());
                $package = [];

                foreach (self::PACKAGE_TABLES as $table) {
                    if ($central->getSchemaBuilder()->hasTable($table)) {
                        $package[$table] = $this->addTable($zip, "package/{$table}.jsonl", $central, $table, 'tenant_id', $tenant, $temp);
                    }
                }

                return ['tables' => $counts, 'package' => $package, 'files' => $this->addFiles($zip, $tenant, $temp)];
            });

            $zip->addFromString('manifest.json', json_encode([
                'format' => self::FORMAT,
                'version' => self::VERSION,
                'exported_at' => now()->toIso8601String(),
                'tenant' => $this->tenantAttributes($tenant),
                'domains' => method_exists($tenant, 'domains') ? $tenant->domains()->get(['host', 'type'])->toArray() : [],
            ] + $manifest, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

            $zip->close();
        } catch (Throwable $e) {
            @$zip->close();
            @unlink($path);

            throw $e;
        } finally {
            array_map(fn (string $file) => @unlink($file), $temp);
        }

        return $path;
    }

    /** @param list<string> $temp */
    private function addTable(ZipArchive $zip, string $entry, Connection $connection, string $table, ?string $column, Tenant $tenant, array &$temp): int
    {
        $file = $temp[] = (string) tempnam(sys_get_temp_dir(), 'atx-export');
        $handle = fopen($file, 'w') ?: throw new RuntimeException("Cannot write [{$file}].");
        $count = 0;

        try {
            foreach (Rows::read($connection, $table, $column, $tenant->getTenantKey()) as $row) {
                fwrite($handle, json_encode($row, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)."\n");
                $count++;
            }
        } finally {
            fclose($handle);
        }

        $zip->addFile($file, $entry);

        return $count;
    }

    /** @param list<string> $temp */
    private function addFiles(ZipArchive $zip, Tenant $tenant, array &$temp): int
    {
        $files = 0;
        $suffix = FilesystemBootstrapper::suffix($tenant);

        foreach ((array) config('tenancy.filesystem.disks', []) as $disk) {
            if (! config("filesystems.disks.{$disk}")) {
                continue;
            }

            /** @var FilesystemAdapter $storage */
            $storage = Storage::disk($disk);
            $local = config("filesystems.disks.{$disk}.driver") === 'local';

            foreach ($storage->allFiles($suffix) as $file) {
                $entry = "files/{$disk}/".substr($file, strlen($suffix) + 1);

                if ($local) {
                    $zip->addFile($storage->path($file), $entry);
                } else {
                    // Remote disks: stream to a temp file instead of holding it in memory.
                    $copy = $temp[] = (string) tempnam(sys_get_temp_dir(), 'atx-export');
                    $in = $storage->readStream($file) ?: throw new RuntimeException("Cannot read [{$disk}:{$file}].");
                    $out = fopen($copy, 'w') ?: throw new RuntimeException("Cannot write [{$copy}].");
                    stream_copy_to_stream($in, $out);
                    fclose($in);
                    fclose($out);
                    $zip->addFile($copy, $entry);
                }

                $files++;
            }
        }

        return $files;
    }

    /** Everything but credentials and operational secrets. @return array<string, mixed> */
    private function tenantAttributes(Tenant&Model $tenant): array
    {
        $attributes = array_diff_key($tenant->attributesToArray(), array_flip(['connection']));

        if (is_array($attributes['data'] ?? null)) {
            unset($attributes['data']['maintenance'], $attributes['data']['provisioning'], $attributes['data']['tenancy_created_database']);
        }

        return $attributes;
    }
}
