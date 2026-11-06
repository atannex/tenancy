<?php

declare(strict_types=1);

namespace Atannex\Tenancy\Database;

use Atannex\Tenancy\Tenancy;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Schema\Blueprint;

final class BlueprintMacros
{
    public static function register(): void
    {
        /**
         * $table->tenant(): indexed tenant key column. Constrained to the tenants
         * table when migrating the central database; left unconstrained in a
         * dedicated tenant database, where the tenants table does not exist.
         * The column type follows the tenant model's key (ULID by default).
         */
        Blueprint::macro('tenant', function (?string $column = null, ?bool $constrained = null) {
            /** @var Blueprint $this */
            $column ??= (string) config('tenancy.database.tenant_column', 'tenant_id');
            // Constrain only on the central database, where the tenants table lives.
            $constrained ??= $this->connection->getName() === app(Tenancy::class)->centralConnection();

            $definition = match (BlueprintMacros::keyType()) {
                'uuid' => $this->foreignUuid($column),
                'int' => $this->foreignId($column),
                default => $this->foreignUlid($column),
            };

            // Always an explicit index: PostgreSQL and SQLite do not index foreign keys,
            // and MySQL reuses this index for the constraint instead of adding a second one.
            $definition->index();

            if ($constrained) {
                $tenantModel = config('tenancy.models.tenant');
                $definition->constrained((new $tenantModel)->getTable())->cascadeOnDelete();
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

    /** ulid | uuid | int: the key type of the configured tenant model. */
    public static function keyType(): string
    {
        $class = config('tenancy.models.tenant');
        $model = new $class;

        return match (true) {
            $model->getKeyType() === 'int' => 'int',
            in_array(HasUuids::class, class_uses_recursive($model), true) => 'uuid',
            default => 'ulid',
        };
    }
}
