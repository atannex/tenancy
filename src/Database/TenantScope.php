<?php

declare(strict_types=1);

namespace Atannex\Tenancy\Database;

use Atannex\Tenancy\Exceptions\NoTenantContext;
use Atannex\Tenancy\Tenancy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

final class TenantScope implements Scope
{
    /** @param Model&BelongsToTenant $model */
    public function apply(Builder $builder, Model $model): void
    {
        $tenancy = app(Tenancy::class);

        if ($tenant = $tenancy->current()) {
            $builder->where($model->getQualifiedTenantColumn(), $tenant->getTenantKey());

            return;
        }

        if ($tenancy->inCentral() || ! $tenancy->strict()) {
            return;
        }

        throw NoTenantContext::forModel($model::class);
    }
}
