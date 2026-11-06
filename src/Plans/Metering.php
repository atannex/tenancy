<?php

declare(strict_types=1);

namespace Atannex\Tenancy\Plans;

use Atannex\Tenancy\Contracts\Tenant;
use Atannex\Tenancy\Tenancy;

final class Metering
{
    public function __construct(private readonly Plans $plans) {}

    public function meter(string $metric, ?Tenant $tenant = null): Meter
    {
        return new Meter($tenant ?? app(Tenancy::class)->currentOrFail('Metering'), $metric, $this->plans);
    }
}
