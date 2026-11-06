<?php

declare(strict_types=1);

namespace Atannex\Tenancy\Support;

use Atannex\Tenancy\Database\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

/**
 * Finds tenant data that is not protected:
 *  - tables with a tenant column whose model is missing BelongsToTenant (or has no model);
 *  - BelongsToTenant models whose table has no index starting with the tenant column.
 */
final class IsolationAudit
{
    /** @return list<string> */
    public static function run(): array
    {
        $column = (string) config('tenancy.database.tenant_column', 'tenant_id');
        $ignored = (array) config('tenancy.audit.ignore_tables', []);
        $connection = (string) config('tenancy.database.central_connection');
        $schema = Schema::connection($connection);

        /** @var array<string, list<class-string<Model>>> $byTable */
        $byTable = [];

        foreach (ModelFinder::in((array) config('tenancy.audit.paths', [])) as $class) {
            $byTable[(new $class)->getTable()][] = $class;
        }

        $problems = [];

        foreach ($schema->getTableListing(schemaQualified: false) as $table) {
            if (in_array($table, $ignored, true) || in_array($table, PackageTables::NAMES, true) || ! $schema->hasColumn($table, $column)) {
                continue;
            }

            $models = $byTable[$table] ?? [];

            if ($models === []) {
                $problems[] = "Table [{$table}] has a [{$column}] column but no model in the audited paths.";

                continue;
            }

            foreach ($models as $model) {
                if (! in_array(BelongsToTenant::class, class_uses_recursive($model), true)) {
                    $problems[] = "Model [{$model}] (table [{$table}]) has a [{$column}] column but does not use BelongsToTenant.";

                    continue;
                }

                $indexed = collect($schema->getIndexes($table))->contains(fn (array $index) => ($index['columns'][0] ?? null) === $column);

                if (! $indexed) {
                    $problems[] = "Table [{$table}] has no index starting with [{$column}]; tenant queries will scan the table.";
                }
            }
        }

        return $problems;
    }
}
