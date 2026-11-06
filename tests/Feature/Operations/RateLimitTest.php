<?php

declare(strict_types=1);

use Atannex\Tenancy\Models\Tenant;
use Atannex\Tenancy\Support\TenantRateLimit;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\Route;

it('gives each tenant its own budget', function () {
    TenantRateLimit::for('tenant-api', fn ($tenant) => Limit::perMinute(2));
    Route::middleware(['tenant', 'throttle:tenant-api'])->get('/limited', fn () => 'ok');
    Tenant::factory()->withDomain('one.test')->create();
    Tenant::factory()->withDomain('two.test')->create();

    $this->get('http://one.test/limited')->assertOk();
    $this->get('http://one.test/limited')->assertOk();
    $this->get('http://one.test/limited')->assertStatus(429);
    $this->get('http://two.test/limited')->assertOk();
});

it('reads per-plan limits', function () {
    config(['tenancy.plans.catalog' => ['tiny' => ['name' => 'Tiny', 'limits' => ['api_per_minute' => 1]]]]);
    TenantRateLimit::perPlan('plan-api', 'api_per_minute');
    Route::middleware(['tenant', 'throttle:plan-api'])->get('/plan-limited', fn () => 'ok');
    Tenant::factory()->withDomain('tiny.test')->create(['plan' => 'tiny']);
    Tenant::factory()->withDomain('free.test')->create();

    $this->get('http://tiny.test/plan-limited')->assertOk();
    $this->get('http://tiny.test/plan-limited')->assertStatus(429);
    $this->get('http://free.test/plan-limited')->assertOk();
    $this->get('http://free.test/plan-limited')->assertOk();
});
