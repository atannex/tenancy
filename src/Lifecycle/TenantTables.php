<?php

declare(strict_types=1);

namespace Atannex\Tenancy\Lifecycle;

use Atannex\Tenancy\Contracts\Tenant;
use Atannex\Tenancy\Database\BelongsToTenant;
use Atannex\Tenancy\Enums\IsolationTier;
use Atannex\Tenancy\Isolation\TenantDatabase;
use Atannex\Tenancy\Support\ModelFinder;
use Atannex\Tenancy\Tenancy;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The tables holding one tenant's rows, and how to reach them: every table
 * of a dedicated database, or the BelongsToTenant tables (filtered by the
 * tenant column) on the shared tier.
 */
final class TenantTables
{
    public function __construct(private readonly Tenancy $tenancy, private readonly TenantDatabase $database) {}

    /** @return array<string, string|null> table => tenant column to filter by (null = whole table) */
    public function for(Tenant $tenant, ?IsolationTier $tier = null): array
    {
        $tier ??= $tenant->isolationTier();

        if ($tier !== IsolationTier::Shared) {
            $schema = Schema::connection($this->connection($tenant, $tier)->getName());
            $tables = array_diff($schema->getTableListing(schemaQualified: false), ['migrations']);

            return array_fill_keys(array_values($tables), null);
        }

        $tables = [];
        $schema = Schema::connection($this->tenancy->centralConnection());

        foreach (ModelFinder::in($this->paths()) as $class) {
            if (in_array(BelongsToTenant::class, class_uses_recursive($class), true)) {
                $model = new $class;

                // Tables that only exist in dedicated tenant databases are not shared-tier data.
                if ($schema->hasTable($model->getTable())) {
                    $tables[$model->getTable()] = $model->getTenantColumn();
                }
            }
        }

        return $tables;
    }

    public function connection(Tenant $tenant, ?IsolationTier $tier = null): Connection
    {
        $tier ??= $tenant->isolationTier();

        if ($tier === IsolationTier::Shared) {
            return DB::connection($this->tenancy->centralConnection());
        }

        return DB::connection($this->database->connect($tenant, 'tenancy_tables', $tier));
    }

    /** @return list<string> */
    private function paths(): array
    {
        return array_values((array) (config('tenancy.lifecycle.model_paths') ?: config('tenancy.audit.paths', [])));
    }
}
