<?php

declare(strict_types=1);

namespace Atannex\Tenancy\Lifecycle;

use Atannex\Tenancy\Bootstrappers\FilesystemBootstrapper;
use Atannex\Tenancy\Contracts\Tenant;
use Atannex\Tenancy\Tenancy;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use ZipArchive;

/**
 * One zip with everything a tenant owns: manifest.json, one JSON-lines file
 * per table (tables/{table}.jsonl) and its files (files/{disk}/…). For GDPR
 * requests and for leaving the platform; Importer reads it back.
 */
final class Exporter
{
    public const string FORMAT = 'atannex-tenancy-export';

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

        $manifest = $this->tenancy->central(function () use ($tenant, $zip): array {
            $connection = $this->tables->connection($tenant);
            $counts = [];
            $temp = [];

            foreach ($this->tables->for($tenant) as $table => $column) {
                $file = tempnam(sys_get_temp_dir(), 'atx-export');
                $temp[] = $file;
                $handle = fopen($file, 'w');
                $query = $connection->table($table);
                $column && $query->where($column, $tenant->getTenantKey());
                $counts[$table] = 0;

                foreach ($query->orderBy($this->firstColumn($connection, $table))->cursor() as $row) {
                    fwrite($handle, json_encode((array) $row, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)."\n");
                    $counts[$table]++;
                }

                fclose($handle);
                $zip->addFile($file, "tables/{$table}.jsonl");
            }

            $files = 0;

            foreach ((array) config('tenancy.filesystem.disks', []) as $disk) {
                if (! config("filesystems.disks.{$disk}")) {
                    continue;
                }

                $suffix = FilesystemBootstrapper::suffix($tenant);

                foreach (Storage::disk($disk)->allFiles($suffix) as $file) {
                    $zip->addFromString("files/{$disk}/".substr($file, strlen($suffix) + 1), (string) Storage::disk($disk)->get($file));
                    $files++;
                }
            }

            register_shutdown_function(fn () => array_map(fn ($f) => @unlink($f), $temp));

            return ['tables' => $counts, 'files' => $files];
        });

        $zip->addFromString('manifest.json', json_encode([
            'format' => self::FORMAT,
            'version' => 1,
            'exported_at' => now()->toIso8601String(),
            'tenant' => $this->tenantAttributes($tenant),
            'domains' => method_exists($tenant, 'domains') ? $tenant->domains()->get(['host', 'type'])->toArray() : [],
        ] + $manifest, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

        $zip->close();

        return $path;
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

    private function firstColumn(Connection $connection, string $table): string
    {
        return $connection->getSchemaBuilder()->getColumnListing($table)[0];
    }
}
