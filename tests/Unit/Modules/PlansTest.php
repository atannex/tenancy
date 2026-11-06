<?php

declare(strict_types=1);

use Atannex\Tenancy\Events\TenantPlanChanged;
use Atannex\Tenancy\Plans\Plans;
use Illuminate\Support\Facades\Event;

require_once __DIR__.'/../../Modules.php';

beforeEach(fn () => definePlans());

it('reads plans from config', function () {
    $plan = app(Plans::class)->find('pro');

    expect($plan->name)->toBe('Pro')
        ->and($plan->has('sso'))->toBeTrue()
        ->and($plan->limit('seats'))->toBeNull()
        ->and($plan->limit('api_calls'))->toBe(1000)
        ->and($plan->period('api_calls'))->toBe('day')
        ->and(app(Plans::class)->all())->toHaveKeys(['free', 'pro']);
});

it('falls back to the default plan', function () {
    config(['tenancy.plans.default' => 'free']);

    expect(app(Plans::class)->for(planTenant())->key)->toBe('free')
        ->and(app(Plans::class)->for(planTenant('pro'))->key)->toBe('pro');
});

it('changes plans and announces it', function () {
    Event::fake([TenantPlanChanged::class]);
    $tenant = planTenant('free');

    app(Plans::class)->change($tenant, 'pro');

    expect($tenant->fresh()->plan)->toBe('pro');
    Event::assertDispatched(TenantPlanChanged::class, fn ($e) => $e->from === 'free' && $e->to === 'pro');
});

it('refuses unknown plans', function () {
    app(Plans::class)->change(planTenant(), 'platinum');
})->throws(InvalidArgumentException::class, 'platinum');

it('exposes plan helpers on the tenant', function () {
    $tenant = planTenant('pro');

    expect($tenant->currentPlan()->key)->toBe('pro')
        ->and($tenant->hasFeature('sso'))->toBeTrue();
});
