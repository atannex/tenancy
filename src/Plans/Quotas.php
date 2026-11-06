<?php

declare(strict_types=1);

namespace Atannex\Tenancy\Plans;

use Atannex\Tenancy\Contracts\Tenant;
use Atannex\Tenancy\Tenancy;
use Closure;

/**
 * Quotas are plan limits checked against usage. Usage comes from a counter
 * registered with countUsing() (e.g. live member count) or, if none, from
 * the metric's meter.
 */
final class Quotas
{
    /** @var array<string, Closure(Tenant): int> */
    private array $counters = [];

    public function __construct(private readonly Plans $plans, private readonly Metering $metering) {}

    /** @param Closure(Tenant): int $counter */
    public function countUsing(string $key, Closure $counter): void
    {
        $this->counters[$key] = $counter;
    }

    public function for(string $key, ?Tenant $tenant = null): Quota
    {
        $tenant ??= app(Tenancy::class)->currentOrFail('Quotas');

        return new Quota($tenant, $key, $this->plans, $this->metering, $this->counters[$key] ?? null);
    }
}
