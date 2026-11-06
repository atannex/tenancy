<?php

declare(strict_types=1);

namespace Atannex\Tenancy\Lifecycle;

use Atannex\Tenancy\Bootstrappers\FilesystemBootstrapper;
use Atannex\Tenancy\Cache\PrefixedStore;
use Atannex\Tenancy\Contracts\Tenant;
use Atannex\Tenancy\Enums\IsolationTier;
use Atannex\Tenancy\Events\TenantPurged;
use Atannex\Tenancy\Isolation\TenantDatabase;
use Atannex\Tenancy\Tenancy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use Throwable;

/** Removes everything a tenant owns: database or rows, files, cache, then the tenant itself. */
final class Purger
{
    public function __construct(
        private readonly Tenancy $tenancy,
        private readonly TenantDatabase $database,
        private readonly TenantTables $tables,
    ) {}

    public function purge(Tenant&Model $tenant): void
    {
        $this->tenancy->central(function () use ($tenant): void {
            $key = $tenant->getTenantKey();
            $slug = $tenant->getTenantSlug();

            // The current tier, and any tier a promotion left data on.
            $tiers = array_unique(array_merge([$tenant->isolationTier()->value], (array) (((array) ($tenant->getAttribute('data') ?? []))['previous_tiers'] ?? [])));

            foreach ($tiers as $tier) {
                $tier = IsolationTier::from($tier);

                if ($tier === IsolationTier::Shared) {
                    $this->deleteSharedRows($tenant);
                } elseif ($this->database->exists($tenant, $tier)) {
                    $this->database->delete($tenant, $tier);
                }
            }

            foreach ((array) config('tenancy.filesystem.disks', []) as $disk) {
                if (config("filesystems.disks.{$disk}")) {
                    Storage::disk($disk)->deleteDirectory(FilesystemBootstrapper::suffix($tenant));
                }
            }

            foreach (array_unique((array) (config('tenancy.cache.stores') ?: [config('cache.default')])) as $name) {
                $store = app('cache')->store($name)->getStore();
                (new PrefixedStore($store instanceof PrefixedStore ? $store->inner() : $store, $key))->flush();
            }

            $tenant->forceDelete();
            event(new TenantPurged($key, $slug));
        });
    }

    /** Repeated passes, so tables that reference each other without cascades still empty. */
    private function deleteSharedRows(Tenant $tenant): void
    {
        $connection = $this->tables->connection($tenant, IsolationTier::Shared);
        $pending = $this->tables->for($tenant, IsolationTier::Shared);

        for ($pass = 0; $pending !== [] && $pass <= count($pending); $pass++) {
            foreach ($pending as $table => $column) {
                try {
                    $connection->table($table)->where($column, $tenant->getTenantKey())->delete();
                    unset($pending[$table]);
                } catch (Throwable) {
                    // Probably a foreign key; try again after the other tables.
                }
            }
        }

        if ($pending !== []) {
            throw new \RuntimeException('Could not purge tenant rows from: '.implode(', ', array_keys($pending)));
        }
    }
}
