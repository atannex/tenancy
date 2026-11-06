<?php

declare(strict_types=1);

namespace Atannex\Tenancy\Lifecycle;

use Atannex\Tenancy\Bootstrappers\FilesystemBootstrapper;
use Atannex\Tenancy\Bootstrappers\RedisBootstrapper;
use Atannex\Tenancy\Cache\PrefixedStore;
use Atannex\Tenancy\Contracts\Tenant;
use Atannex\Tenancy\Enums\IsolationTier;
use Atannex\Tenancy\Events\TenantPurged;
use Atannex\Tenancy\Events\TenantPurging;
use Atannex\Tenancy\Isolation\TenantDatabase;
use Atannex\Tenancy\Support\PackageTables;
use Atannex\Tenancy\Tenancy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Removes everything a tenant owns: database or rows, files, cache, Redis
 * keys, the package's per-tenant records, then the tenant itself. The audit
 * log is kept (tenancy:prune-audit ages it out). Listen for TenantPurging to
 * remove data the package cannot see (search indexes, outside services).
 */
final class Purger
{
    /** @var array<string, true> keys of tenants being purged right now */
    private static array $purging = [];

    /** Whether the purge is deleting this tenant (the only allowed delete). */
    public static function purging(Tenant $tenant): bool
    {
        return isset(self::$purging[$tenant->getTenantKey()]);
    }

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

            event(new TenantPurging($tenant));

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

            $this->deleteRedisKeys($tenant);

            // Foreign keys cascade these, but not where constraints are off (SQLite by default).
            $central = DB::connection($this->tenancy->centralConnection());

            foreach (array_diff(PackageTables::NAMES, ['tenants', 'tenant_audit_events']) as $table) {
                $central->getSchemaBuilder()->hasTable($table) && $central->table($table)->where('tenant_id', $key)->delete();
            }

            self::$purging[$key] = true;

            try {
                $tenant->forceDelete();
            } finally {
                unset(self::$purging[$key]);
            }

            event(new TenantPurged($key, $slug));
        });
    }

    /** Keys written under the RedisBootstrapper prefix, removed with SCAN so Redis never blocks. */
    private function deleteRedisKeys(Tenant $tenant): void
    {
        if (! in_array(RedisBootstrapper::class, (array) config('tenancy.bootstrappers', []), true)) {
            return;
        }

        foreach ((array) config('tenancy.redis.connections', []) as $name) {
            $client = Redis::connection($name)->client();

            if (! $client instanceof \Redis) {
                continue;
            }

            $original = (string) $client->getOption(\Redis::OPT_PREFIX);
            $pattern = addcslashes($original.RedisBootstrapper::prefix($tenant), '*?[]\\').'*';
            $client->setOption(\Redis::OPT_PREFIX, '');

            try {
                $cursor = null;

                do {
                    $keys = $client->scan($cursor, $pattern, 1000);
                    $keys && $client->del($keys);
                } while ($cursor > 0);
            } finally {
                $client->setOption(\Redis::OPT_PREFIX, $original);
            }
        }
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
