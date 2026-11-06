<?php

declare(strict_types=1);

use Atannex\Tenancy\Tests\Fixtures\Models\PlanTenant;
use Atannex\Tenancy\Tests\Fixtures\Models\User;
use Illuminate\Support\Str;

/** Shared helpers for the module tests. */
function moduleUser(string $name = 'Ada', ?string $email = null): User
{
    return User::create(['name' => $name, 'email' => $email ?? strtolower($name).uniqid().'@example.test']);
}

function planTenant(?string $plan = null, array $attributes = []): PlanTenant
{
    return PlanTenant::create(array_merge(['name' => 'Plan '.Str::lower(Str::random(8)), 'plan' => $plan], $attributes));
}

function definePlans(): void
{
    config(['tenancy.plans.catalog' => [
        'free' => ['name' => 'Free', 'features' => ['reports'], 'limits' => ['seats' => 2, 'api_calls' => ['limit' => 10, 'period' => 'month']]],
        'pro' => ['name' => 'Pro', 'features' => ['reports', 'sso'], 'limits' => ['seats' => null, 'api_calls' => ['limit' => 1000, 'period' => 'day']]],
    ]]);
}
