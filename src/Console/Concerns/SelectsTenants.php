<?php

declare(strict_types=1);

namespace Atannex\Tenancy\Console\Concerns;

use Atannex\Tenancy\Contracts\Tenant;
use Atannex\Tenancy\Enums\TenantStatus;
use Atannex\Tenancy\Tenancy;
use Closure;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

/**
 * --tenants=key-or-slug (repeatable) picks tenants in any status; without it
 * every active tenant is used. --tier= narrows to one isolation tier.
 *
 * @mixin Command
 */
trait SelectsTenants
{
    protected function tenantOptions(): string
    {
        return '{--tenants=* : Tenant keys or slugs (default: every active tenant)} {--tier= : Only tenants on this isolation tier}';
    }

    /** @param Closure(Tenant): mixed $callback */
    protected function eachTenant(Closure $callback): int
    {
        return app(Tenancy::class)->each($callback, fn (Builder $query) => $this->filterTenants($query));
    }

    protected function filterTenants(Builder $query): Builder
    {
        $selected = array_values(array_filter((array) $this->option('tenants')));

        if ($selected !== []) {
            $keyName = $query->getModel()->getKeyName();
            $query->where(fn (Builder $q) => $q->whereIn($keyName, $selected)->orWhereIn('slug', array_map('strtolower', $selected)));
        } else {
            $query->where('status', TenantStatus::Active->value);
        }

        if ($tier = $this->option('tier')) {
            $query->where('isolation', $tier);
        }

        return $query;
    }
}
