<?php

declare(strict_types=1);

namespace Atannex\Tenancy\Database;

use Atannex\Tenancy\Enums\IsolationTier;
use Atannex\Tenancy\Tenancy;
use Illuminate\Database\Schema\Blueprint;

final class BlueprintMacros
{
    public static function register(): void
    {
        /**
         * $table->tenant(): indexed tenant key column. Constrained to the tenants
         * table on the shared tier; left unconstrained inside a dedicated
         * tenant database, where the tenants table does not exist.
         */
        Blueprint::macro('tenant', function (?string $column = null, ?bool $constrained = null) {
            /** @var Blueprint $this */
            $column ??= (string) config('tenancy.database.tenant_column', 'tenant_id');
            $current = app(Tenancy::class)->current();
            $constrained ??= $current === null || $current->isolationTier() === IsolationTier::Shared;

            $definition = match (config('tenancy.database.key_type', 'ulid')) {
                'uuid' => $this->foreignUuid($column),
                'int' => $this->foreignId($column),
                default => $this->foreignUlid($column),
            };

            if ($constrained) {
                $tenantModel = config('tenancy.models.tenant');
                $definition->constrained((new $tenantModel)->getTable())->cascadeOnDelete();
            } else {
                $definition->index();
            }

            return $definition;
        });

        /** $table->tenantUnique('slug'): unique per tenant, tenant column first. */
        Blueprint::macro('tenantUnique', function (string|array $columns, ?string $index = null) {
            /** @var Blueprint $this */
            $tenantColumn = (string) config('tenancy.database.tenant_column', 'tenant_id');

            return $this->unique(array_merge([$tenantColumn], (array) $columns), $index);
        });
    }
}
