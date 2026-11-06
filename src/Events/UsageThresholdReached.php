<?php

declare(strict_types=1);

namespace Atannex\Tenancy\Events;

use Atannex\Tenancy\Contracts\Tenant;

final readonly class UsageThresholdReached
{
    public function __construct(public Tenant $tenant, public string $metric, public int $threshold, public int $value, public int $limit) {}
}
