<?php

declare(strict_types=1);

namespace Atannex\Tenancy\Database;

use Atannex\Tenancy\Enums\IsolationTier;
use Atannex\Tenancy\Exceptions\NoTenantContext;
use Atannex\Tenancy\Exceptions\TenantMismatch;
use Atannex\Tenancy\Tenancy;
use Illuminate\Contracts\Database\Query\Expression as ExpressionContract;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Expression;
use LogicException;

/**
 * Bulk writes skip model events, so the builder guards them itself:
 *
 * - update(), updateFrom() and the increments may not rewrite the tenant
 *   column inside a tenant;
 * - insert() and upsert() rows are stamped with (and checked against) the
 *   current tenant, and upserts must be keyed by the tenant column;
 * - updateOrInsert() runs scoped and stamps what it inserts;
 * - truncate(), insertUsing() and insertOrIgnoreUsing() would reach every
 *   tenant's rows (or can't be stamped), so they run in central code only.
 *
 * Eloquent sends methods its builder doesn't define straight to the base
 * query, without global scopes. Every write the base query offers is
 * therefore defined here.
 *
 * Models with their own Eloquent builder use this trait on that builder.
 *
 * @mixin Builder
 */
trait GuardsTenantWrites
{
    public function update(array $values)
    {
        $this->guardTenantColumn($values);

        return parent::update($values);
    }

    public function updateFrom(array $values)
    {
        $this->guardTenantColumn($values);

        return $this->toBase()->updateFrom($this->addUpdatedAtColumn($values));
    }

    public function increment($column, $amount = 1, array $extra = [])
    {
        $this->guardTenantColumn([(string) $column => null] + $extra, always: true);

        return parent::increment($column, $amount, $extra);
    }

    public function decrement($column, $amount = 1, array $extra = [])
    {
        $this->guardTenantColumn([(string) $column => null] + $extra, always: true);

        return parent::decrement($column, $amount, $extra);
    }

    public function incrementEach(array $columns, array $extra = [])
    {
        $this->guardTenantColumn(array_fill_keys(array_keys($columns), null) + $extra, always: true);

        // Through the scoped base query: Laravel 12's Eloquent builder has no
        // incrementEach(), and __call would forward it unscoped.
        return $this->toBase()->incrementEach($columns, $this->addUpdatedAtColumn($extra));
    }

    public function decrementEach(array $columns, array $extra = [])
    {
        $this->guardTenantColumn(array_fill_keys(array_keys($columns), null) + $extra, always: true);

        return $this->toBase()->decrementEach($columns, $this->addUpdatedAtColumn($extra));
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

    public function insertOrIgnoreReturning(array $values, array $returning = ['*'], array|string|null $uniqueBy = null)
    {
        return $this->toBase()->insertOrIgnoreReturning($this->stampTenantRows($values), $returning, $uniqueBy);
    }

    /** Rows selected by a query can't be stamped: central code only. */
    public function insertUsing(array $columns, $query)
    {
        $this->assertCentralWrite('insertUsing');

        return $this->toBase()->insertUsing($columns, $query);
    }

    /** Rows selected by a query can't be stamped: central code only. */
    public function insertOrIgnoreUsing(array $columns, $query)
    {
        $this->assertCentralWrite('insertOrIgnoreUsing');

        return $this->toBase()->insertOrIgnoreUsing($columns, $query);
    }

    /**
     * The base query's updateOrInsert, but scoped to the tenant and stamping
     * the row it inserts.
     *
     * @param  array<string, mixed>  $attributes
     * @param  array<string, mixed>|callable(bool): array<string, mixed>  $values
     */
    public function updateOrInsert(array $attributes, array|callable $values = [])
    {
        $exists = $this->clone()->where($attributes)->exists();

        if (! is_array($values)) {
            $values = $values($exists);
        }

        if (! $exists) {
            return $this->insert(array_merge($attributes, $values));
        }

        if ($values === []) {
            return true;
        }

        $this->guardTenantColumn($values);

        return (bool) $this->where($attributes)->toBase()->limit(1)->update($values);
    }

    /** Empties the table for every tenant: central code only (or a tenant with a database of its own). */
    public function truncate()
    {
        $current = app(Tenancy::class)->current();

        // A model on a connection of its own (e.g. the central one) is shared
        // by every tenant even when the current tenant has its own database.
        if ($current === null || $current->isolationTier() === IsolationTier::Shared || $this->getModel()->getConnectionName() !== null) {
            $this->assertCentralWrite('truncate');
        }

        $this->toBase()->truncate();
    }

    /**
     * The tenant column is never part of the update: a row's tenant can't
     * change. On MySQL and MariaDB, ON DUPLICATE KEY UPDATE fires on any
     * unique key, whatever uniqueBy says, so a row naming another tenant's
     * primary key (or another globally unique value) would update that
     * tenant's row. There, each updated column is wrapped in
     * IF(tenant = inserted tenant, new, old): a duplicate that belongs to
     * another tenant is left exactly as it was.
     */
    public function upsert(array $values, mixed $uniqueBy, $update = null)
    {
        $column = $this->getModel()->getTenantColumn();

        if (app(Tenancy::class)->current() !== null && ! in_array($column, (array) $uniqueBy, true)) {
            throw TenantMismatch::upsertKey($this->getModel()::class, $column);
        }

        $rows = $this->stampTenantRows($values);

        if ($rows === []) {
            return 0;
        }

        $update ??= array_keys($rows[0]);
        $rows = $this->addTimestampsToUpsertValues($this->addUniqueIdsToUpsertValues($rows));
        $update = $this->withoutTenantColumn($this->addUpdatedAtToUpsertColumns($update), $column);

        // Nothing left to update: existing rows stay as they are.
        if ($update === []) {
            return $this->toBase()->insertOrIgnore($rows);
        }

        if (in_array($this->sqlConnection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            $update = $this->sameTenantOnly($update, $column);
        }

        return $this->toBase()->upsert($rows, $uniqueBy, $update);
    }

    private function sqlConnection(): Connection
    {
        $connection = $this->getQuery()->getConnection();

        return $connection instanceof Connection
            ? $connection
            : throw new LogicException('Tenant upserts need an Illuminate\Database\Connection.');
    }

    /**
     * @param  array<int|string, mixed>  $update
     * @return array<int|string, mixed>
     */
    private function withoutTenantColumn(array $update, string $column): array
    {
        $qualified = $this->getModel()->qualifyColumn($column);

        foreach ($update as $key => $value) {
            $name = is_int($key) ? $value : $key;

            if ($name === $column || $name === $qualified) {
                unset($update[$key]);
            }
        }

        return $update;
    }

    /**
     * @param  array<int|string, mixed>  $update
     * @return array<string, Expression>
     */
    private function sameTenantOnly(array $update, string $column): array
    {
        $connection = $this->sqlConnection();
        $grammar = $connection->getQueryGrammar();
        $alias = $connection->getConfig('use_upsert_alias');
        $inserted = fn (string $name) => $alias
            ? $grammar->wrap('laravel_upsert_alias').'.'.$grammar->wrap($name)
            : 'values('.$grammar->wrap($name).')';
        $same = $grammar->wrap($column).' = '.$inserted($column);
        $guarded = [];

        foreach ($update as $key => $value) {
            $name = (string) (is_int($key) ? $value : $key);
            $value = match (true) {
                $value instanceof \BackedEnum => $value->value,
                $value instanceof \DateTimeInterface => $value->format($grammar->getDateFormat()),
                default => $value,
            };
            $new = match (true) {
                is_int($key) => $inserted($name),
                $value instanceof ExpressionContract => (string) $value->getValue($grammar),
                $value === null => 'null',
                is_bool($value) => $value ? '1' : '0',
                is_int($value) || is_float($value) => (string) $value,
                default => $connection->escape(is_string($value) ? $value : (string) $value),
            };

            $guarded[$name] = new Expression("if({$same}, {$new}, {$grammar->wrap($name)})");
        }

        return $guarded;
    }

    /**
     * Inside a tenant, refuse values that set the tenant column to another
     * tenant ($always: refuse touching the column at all, as increments do).
     *
     * @param  array<string, mixed>  $values
     */
    protected function guardTenantColumn(array $values, bool $always = false): void
    {
        $column = $this->getModel()->getTenantColumn();
        $current = app(Tenancy::class)->current();

        foreach ([$column, $this->getModel()->qualifyColumn($column)] as $key) {
            if (! array_key_exists($key, $values)) {
                continue;
            }

            if ($always || ($current !== null && (string) $values[$key] !== $current->getTenantKey())) {
                throw TenantMismatch::immutable($this->getModel()::class);
            }
        }
    }

    /** Writes the guards can't scope or stamp run in central code (or with strict mode off and no tenant). */
    protected function assertCentralWrite(string $operation): void
    {
        $tenancy = app(Tenancy::class);
        $model = $this->getModel()::class;

        if ($tenancy->current() !== null) {
            throw TenantMismatch::centralOnly($model, $operation);
        }

        if (! $tenancy->inCentral() && $tenancy->strict()) {
            throw NoTenantContext::forModel($model);
        }
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
