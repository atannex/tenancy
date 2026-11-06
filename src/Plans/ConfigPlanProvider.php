<?php

declare(strict_types=1);

namespace Atannex\Tenancy\Plans;

use Atannex\Tenancy\Contracts\PlanProvider;

/** Plans from tenancy.plans.catalog. */
final class ConfigPlanProvider implements PlanProvider
{
    public function find(string $key): ?Plan
    {
        return $this->all()[$key] ?? null;
    }

    public function all(): array
    {
        $plans = [];

        foreach ((array) config('tenancy.plans.catalog', []) as $key => $definition) {
            $plans[(string) $key] = Plan::fromArray((string) $key, (array) $definition);
        }

        return $plans;
    }
}
