<?php

declare(strict_types=1);

namespace Atannex\Tenancy\Plans;

use Atannex\Tenancy\Models\Membership;
use Atannex\Tenancy\Models\Tenant;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Plan, feature, quota, metering, settings and member helpers for the
 * tenant model.
 *
 * @mixin Tenant
 */
trait InteractsWithPlans
{
    public function currentPlan(): ?Plan
    {
        return app(Plans::class)->for($this);
    }

    public function hasFeature(string $feature): bool
    {
        return app(Features::class)->active($feature, $this);
    }

    public function quota(string $key): Quota
    {
        return app(Quotas::class)->for($key, $this);
    }

    public function meter(string $metric): Meter
    {
        return app(Metering::class)->meter($metric, $this);
    }

    public function settings(): TenantSettings
    {
        return app(Settings::class)->for($this);
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(Membership::class, 'tenant_id');
    }
}
