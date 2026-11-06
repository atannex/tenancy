<?php

declare(strict_types=1);

namespace Atannex\Tenancy\Database;

use Atannex\Tenancy\Contracts\Tenant;
use Atannex\Tenancy\Exceptions\NoTenantContext;
use Atannex\Tenancy\Exceptions\TenantMismatch;
use Atannex\Tenancy\Tenancy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tenant-owned data. Queries see only the current tenant; with no tenant
 * they fail closed (strict mode) unless run inside Tenancy::central().
 *
 * @mixin Model
 */
trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope(new TenantScope);

        static::creating(function (Model $model): void {
            /** @var Model&self $model */
            $tenancy = app(Tenancy::class);
            $column = $model->getTenantColumn();
            $given = $model->getAttribute($column);
            $current = $tenancy->current();

            if ($current !== null) {
                if ($given !== null && (string) $given !== $current->getTenantKey()) {
                    throw TenantMismatch::foreign($model::class);
                }

                $model->setAttribute($column, $current->getTenantKey());

                return;
            }

            if ($given === null) {
                throw NoTenantContext::forModel($model::class);
            }

            if (! $tenancy->inCentral() && $tenancy->strict()) {
                throw NoTenantContext::forModel($model::class);
            }
        });

        static::updating(function (Model $model): void {
            /** @var Model&self $model */
            if ($model->isDirty($model->getTenantColumn())) {
                throw TenantMismatch::immutable($model::class);
            }

            $model->assertWritableInContext();
        });

        // Saves and deletes of a loaded instance skip global scopes; check ownership here.
        static::deleting(function (Model $model): void {
            /** @var Model&self $model */
            $model->assertWritableInContext();
        });
    }

    /** Use the guarded builder. Models with their own builder use GuardsTenantWrites on it. */
    public function newEloquentBuilder($query): TenantBuilder
    {
        return new TenantBuilder($query);
    }

    protected function assertWritableInContext(): void
    {
        $tenancy = app(Tenancy::class);
        $current = $tenancy->current();

        if ($current !== null) {
            if ((string) $this->getOriginal($this->getTenantColumn()) !== $current->getTenantKey()) {
                throw TenantMismatch::foreignRow(static::class);
            }

            return;
        }

        if (! $tenancy->inCentral() && $tenancy->strict()) {
            throw NoTenantContext::forModel(static::class);
        }
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(config('tenancy.models.tenant'), $this->getTenantColumn());
    }

    /** Override to use another column for one model. */
    public function getTenantColumn(): string
    {
        return (string) config('tenancy.database.tenant_column', 'tenant_id');
    }

    public function getQualifiedTenantColumn(): string
    {
        return $this->qualifyColumn($this->getTenantColumn());
    }

    public function belongsToCurrentTenant(): bool
    {
        $current = app(Tenancy::class)->current();

        return $current !== null && (string) $this->getAttribute($this->getTenantColumn()) === $current->getTenantKey();
    }

    /** Explicitly target one tenant (lifts the context scope). */
    public function scopeForTenant(Builder $query, Tenant|string $tenant): void
    {
        $key = $tenant instanceof Tenant ? $tenant->getTenantKey() : $tenant;

        $query->withoutGlobalScope(TenantScope::class)->where($this->getQualifiedTenantColumn(), $key);
    }
}
