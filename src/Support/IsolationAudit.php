<?php

declare(strict_types=1);

namespace Atannex\Tenancy\Support;

use Atannex\Tenancy\Database\BelongsToTenant;
use Atannex\Tenancy\Lifecycle\TenantTables;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Builder;
use Illuminate\Support\Facades\Schema;

/**
 * Finds tenant data that is not protected, on every connection the audited
 * models use (table prefixes included):
 *  - tables with a tenant column whose model is missing BelongsToTenant (or has no model);
 *  - BelongsToTenant models whose table lacks their tenant column (each model's own column);
 *  - BelongsToTenant tables with no index starting with that column.
 */
final class IsolationAudit
{
    /** @return list<string> */
    public static function run(): array
    {
        $default = (string) config('tenancy.database.tenant_column', 'tenant_id');
        $ignored = array_merge((array) config('tenancy.audit.ignore_tables', []), PackageTables::NAMES);
        $central = (string) config('tenancy.database.central_connection');

        /** @var array<string, array<string, list<Model>>> $models connection => table => models */
        $models = [$central => []];

        foreach (ModelFinder::in((array) config('tenancy.audit.paths', [])) as $class) {
            $model = new $class;
            $models[$model->getConnection()->getName()][$model->getTable()][] = $model;
        }

        $problems = [];

        foreach ($models as $connection => $byTable) {
            $schema = Schema::connection($connection);
            $prefix = $schema->getConnection()->getTablePrefix();
            $tables = array_map(
                fn (string $table) => $prefix !== '' && str_starts_with($table, $prefix) ? substr($table, strlen($prefix)) : $table,
                TenantTables::listing($schema),
            );

            foreach ($tables as $table) {
                if (in_array($table, $ignored, true)) {
                    continue;
                }

                $owners = $byTable[$table] ?? [];

                if ($owners === []) {
                    if ($schema->hasColumn($table, $default)) {
                        $problems[] = "Table [{$table}] has a [{$default}] column but no model in the audited paths.";
                    }

                    continue;
                }

                foreach ($owners as $model) {
                    $problems = array_merge($problems, self::model($schema, $table, $model, $default));
                }
            }
        }

        return $problems;
    }

    /** @return list<string> */
    private static function model(Builder $schema, string $table, Model $model, string $default): array
    {
        $class = $model::class;

        if (! in_array(BelongsToTenant::class, class_uses_recursive($class), true)) {
            return $schema->hasColumn($table, $default)
                ? ["Model [{$class}] (table [{$table}]) has a [{$default}] column but does not use BelongsToTenant."]
                : [];
        }

        // $model uses BelongsToTenant (checked above).
        $column = $model->getTenantColumn();

        if (! $schema->hasColumn($table, $column)) {
            return ["Model [{$class}] uses BelongsToTenant but table [{$table}] has no [{$column}] column."];
        }

        $indexed = collect($schema->getIndexes($table))->contains(fn (array $index) => ($index['columns'][0] ?? null) === $column);

        return $indexed ? [] : ["Table [{$table}] has no index starting with [{$column}]; tenant queries will scan the table."];
    }
}
