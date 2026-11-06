<?php

declare(strict_types=1);

namespace Atannex\Tenancy\Lifecycle;

use Atannex\Tenancy\Contracts\Tenant;
use Atannex\Tenancy\Enums\IsolationTier;
use Atannex\Tenancy\Events\TenantPromoted;
use Atannex\Tenancy\Isolation\TenantDatabase;
use Atannex\Tenancy\Tenancy;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Moves a tenant to another isolation tier: maintenance on, target created
 * and migrated, rows copied table by table and counted, tier flipped,
 * maintenance off. The source is kept unless $deleteSource; on any failure
 * the target is removed and the tenant stays where it was.
 */
final class Promoter
{
    private const string TARGET = 'tenancy_promotion';

    public function __construct(
        private readonly Tenancy $tenancy,
        private readonly TenantDatabase $database,
        private readonly TenantTables $tables,
        private readonly Maintenance $maintenance,
    ) {}

    public function promote(Tenant&Model $tenant, IsolationTier $to, bool $deleteSource = false): void
    {
        $from = $tenant->isolationTier();

        if ($from === $to) {
            throw new InvalidArgumentException("Tenant [{$tenant->getTenantSlug()}] is already on the {$to->value} tier.");
        }

        if ($from !== IsolationTier::Shared && $to !== IsolationTier::Shared) {
            throw new InvalidArgumentException('Move between dedicated tiers through the shared tier, or export and import.');
        }

        $this->tenancy->central(function () use ($tenant, $from, $to, $deleteSource): void {
            $wasDown = $this->maintenance->isDown($tenant);
            $wasDown || $this->maintenance->down($tenant, ['message' => 'Moving data, back shortly.', 'retry' => 60, 'hold_jobs' => true]);
            $sourceTables = $this->tables->for($tenant, $from);
            $source = $this->tables->connection($tenant, $from);

            try {
                $target = $this->prepareTarget($tenant, $to);
                $this->copy($tenant, $source, $target, $sourceTables, $to === IsolationTier::Shared);
            } catch (Throwable $e) {
                $this->discardTarget($tenant, $to, $sourceTables);
                $wasDown || $this->maintenance->up($tenant->refresh());

                throw $e;
            }

            $data = (array) ($tenant->getAttribute('data') ?? []);

            if ($deleteSource) {
                $from === IsolationTier::Shared
                    ? $this->deleteRows(DB::connection($this->tenancy->centralConnection()), $sourceTables, $tenant)
                    : $this->database->delete($tenant, $from);
            } else {
                // Kept for safety; the purge removes it with the tenant.
                $data['previous_tiers'] = array_values(array_unique(array_merge((array) ($data['previous_tiers'] ?? []), [$from->value])));
            }

            $tenant->forceFill(['isolation' => $to, 'data' => $data])->save();

            $wasDown || $this->maintenance->up($tenant);
            event(new TenantPromoted($tenant));
        });
    }

    private function prepareTarget(Tenant&Model $tenant, IsolationTier $to): Connection
    {
        if ($to === IsolationTier::Shared) {
            return DB::connection($this->tenancy->centralConnection());
        }

        $this->database->create($tenant, $to);
        $connection = $this->database->connect($tenant, self::TARGET, $to);

        $code = Artisan::call('migrate', [
            '--database' => $connection,
            '--path' => array_values((array) config('tenancy.migrations.paths', [])),
            '--realpath' => true,
            '--force' => true,
        ]);

        if ($code !== 0) {
            throw new RuntimeException('Migrating the target database failed: '.Artisan::output());
        }

        return DB::connection($connection);
    }

    /** @param array<string, string|null> $tables */
    private function copy(Tenant $tenant, Connection $source, Connection $target, array $tables, bool $intoShared): void
    {
        $key = $tenant->getTenantKey();
        $column = (string) config('tenancy.database.tenant_column', 'tenant_id');

        $target->transaction(function () use ($tenant, $source, $target, $tables, $key, $column, $intoShared): void {
            foreach ($tables as $table => $filter) {
                if (! $target->getSchemaBuilder()->hasTable($table)) {
                    throw new RuntimeException("Table [{$table}] is missing on the target; add it to the tenant migrations.");
                }

                $query = $source->table($table);
                $filter && $query->where($filter, $key);
                $first = $source->getSchemaBuilder()->getColumnListing($table)[0];
                $batch = [];
                $copied = 0;

                foreach ($query->orderBy($first)->cursor() as $row) {
                    $row = (array) $row;
                    array_key_exists($column, $row) && $row[$column] = $key;
                    $batch[] = $row;

                    if (count($batch) === 500) {
                        $target->table($table)->insert($batch);
                        $copied += 500;
                        $batch = [];
                    }
                }

                if ($batch !== []) {
                    $target->table($table)->insert($batch);
                    $copied += count($batch);
                }

                $landed = $target->table($table);
                $intoShared && $target->getSchemaBuilder()->hasColumn($table, $column) && $landed->where($column, $key);

                if ($landed->count() !== $copied) {
                    throw new RuntimeException("Row count mismatch for [{$table}] while moving tenant [{$tenant->getTenantSlug()}].");
                }
            }
        });
    }

    /** @param array<string, string|null> $sourceTables */
    private function discardTarget(Tenant&Model $tenant, IsolationTier $to, array $sourceTables): void
    {
        try {
            if ($to !== IsolationTier::Shared) {
                DB::purge(self::TARGET);
                $this->database->delete($tenant, $to);
            }
        } catch (Throwable $e) {
            report($e);
        }
    }

    /** @param array<string, string|null> $tables */
    private function deleteRows(Connection $connection, array $tables, Tenant $tenant): void
    {
        foreach (array_reverse($tables, true) as $table => $column) {
            $column && $connection->table($table)->where($column, $tenant->getTenantKey())->delete();
        }
    }
}
