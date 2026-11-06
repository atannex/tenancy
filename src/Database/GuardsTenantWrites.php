<?php

declare(strict_types=1);

namespace Atannex\Tenancy\Database;

use Atannex\Tenancy\Exceptions\NoTenantContext;
use Atannex\Tenancy\Exceptions\TenantMismatch;
use Atannex\Tenancy\Tenancy;
use Illuminate\Database\Eloquent\Builder;

/**
 * Bulk writes skip model events, so the builder guards them itself:
 * update() may not rewrite the tenant column inside a tenant, insert() and
 * upsert() rows are stamped with (and checked against) the current tenant,
 * and upserts must be keyed by the tenant column.
 *
 * Models with their own Eloquent builder use this trait on that builder.
 *
 * @mixin Builder
 */
trait GuardsTenantWrites
{
    public function update(array $values)
    {
        $column = $this->getModel()->getTenantColumn();
        $current = app(Tenancy::class)->current();

        foreach ([$column, $this->getModel()->qualifyColumn($column)] as $key) {
            if ($current !== null && array_key_exists($key, $values) && (string) $values[$key] !== $current->getTenantKey()) {
                throw TenantMismatch::immutable($this->getModel()::class);
            }
        }

        return parent::update($values);
    }

    public function insert(array $values)
    {
        return $this->toBase()->insert($this->stampTenantRows($values));
    }

    public function insertOrIgnore(array $values)
    {
        return $this->toBase()->insertOrIgnore($this->stampTenantRows($values));
    }

    public function insertGetId(array $values, $sequence = null)
    {
        return $this->toBase()->insertGetId($this->stampTenantRows($values)[0], $sequence);
    }

    public function upsert(array $values, mixed $uniqueBy, $update = null)
    {
        $column = $this->getModel()->getTenantColumn();

        if (app(Tenancy::class)->current() !== null && ! in_array($column, (array) $uniqueBy, true)) {
            throw TenantMismatch::upsertKey($this->getModel()::class, $column);
        }

        return parent::upsert($this->stampTenantRows($values), $uniqueBy, $update);
    }

    /**
     * @param  array<mixed>  $values  one row or a list of rows
     * @return list<array<string, mixed>>
     */
    protected function stampTenantRows(array $values): array
    {
        if ($values === []) {
            return [];
        }

        $rows = array_is_list($values) && is_array($values[0]) ? $values : [$values];
        $model = $this->getModel();
        $column = $model->getTenantColumn();
        $tenancy = app(Tenancy::class);
        $current = $tenancy->current();

        foreach ($rows as $i => $row) {
            $given = $row[$column] ?? null;

            if ($current !== null) {
                if ($given !== null && (string) $given !== $current->getTenantKey()) {
                    throw TenantMismatch::foreign($model::class);
                }

                $rows[$i][$column] = $current->getTenantKey();

                continue;
            }

            if ($given === null || (! $tenancy->inCentral() && $tenancy->strict())) {
                throw NoTenantContext::forModel($model::class);
            }
        }

        return $rows;
    }
}
