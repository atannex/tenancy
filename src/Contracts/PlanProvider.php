<?php

declare(strict_types=1);

namespace Atannex\Tenancy\Contracts;

use Atannex\Tenancy\Plans\Plan;

interface PlanProvider
{
    public function find(string $key): ?Plan;

    /** @return array<string, Plan> */
    public function all(): array;
}
