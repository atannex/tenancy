<?php

declare(strict_types=1);

namespace Atannex\Tenancy\Plans;

use Atannex\Tenancy\Contracts\PlanProvider;
use Atannex\Tenancy\Contracts\Tenant;
use Atannex\Tenancy\Events\TenantPlanChanged;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

final class Plans
{
    public function provider(): PlanProvider
    {
        return app(config('tenancy.plans.provider', ConfigPlanProvider::class));
    }

    public function find(string $key): ?Plan
    {
        return $this->provider()->find($key);
    }

    /** @return array<string, Plan> */
    public function all(): array
    {
        return $this->provider()->all();
    }

    /** The tenant's plan, or the default plan. */
    public function for(Tenant $tenant): ?Plan
    {
        $key = data_get($tenant, 'plan') ?: config('tenancy.plans.default');

        return $key ? $this->find((string) $key) : null;
    }

    /**
     * A limit for this tenant: a per-tenant override in data.limits wins,
     * then the plan. Null means unlimited.
     */
    public function limit(Tenant $tenant, string $key): ?int
    {
        $overrides = data_get($tenant, 'data.limits');

        if ((is_array($overrides) || $overrides instanceof \ArrayAccess) && isset($overrides[$key])) {
            return (int) $overrides[$key];
        }

        return $this->for($tenant)?->limit($key);
    }

    public function period(Tenant $tenant, string $key): string
    {
        return $this->for($tenant)?->period($key)
            ?? config("tenancy.metering.periods.{$key}")
            ?? (string) config('tenancy.metering.default_period', 'month');
    }

    /** Move a tenant to a plan (null removes it). */
    public function change(Tenant&Model $tenant, ?string $key): void
    {
        if ($key !== null && $this->find($key) === null) {
            throw new InvalidArgumentException("Unknown plan [{$key}].");
        }

        $from = $tenant->getAttribute('plan');
        $tenant->forceFill(['plan' => $key])->save();

        if ($from !== $key) {
            event(new TenantPlanChanged($tenant, $from, $key));
        }
    }
}
