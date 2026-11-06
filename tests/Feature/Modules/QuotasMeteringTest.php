<?php

declare(strict_types=1);

use Atannex\Tenancy\Events\UsageThresholdReached;
use Atannex\Tenancy\Exceptions\QuotaExceeded;
use Atannex\Tenancy\Facades\Tenancy;
use Atannex\Tenancy\Plans\Metering;
use Atannex\Tenancy\Plans\Quotas;
use Illuminate\Support\Facades\Event;

require_once __DIR__.'/../../Modules.php';

beforeEach(fn () => definePlans());

it('counts quotas with a counter', function () {
    $tenant = planTenant('free');
    app(Quotas::class)->countUsing('projects', fn ($t) => 3);
    $tenant->update(['data' => ['limits' => ['projects' => 5]]]);

    $quota = app(Quotas::class)->for('projects', $tenant);

    expect($quota->limit())->toBe(5)->and($quota->used())->toBe(3)->and($quota->remaining())->toBe(2)
        ->and($quota->allows(2))->toBeTrue()->and($quota->allows(3))->toBeFalse();
});

it('treats missing limits as unlimited', function () {
    $quota = app(Quotas::class)->for('seats', planTenant('pro'));

    expect($quota->limit())->toBeNull()->and($quota->remaining())->toBeNull()->and($quota->allows(10_000))->toBeTrue();
});

it('consumes metered quotas and stops at the limit', function () {
    $tenant = planTenant('free');
    $quota = app(Quotas::class)->for('api_calls', $tenant);

    $quota->consume(9);
    expect($quota->used())->toBe(9);

    $quota->consume(2);
})->throws(QuotaExceeded::class);

it('renders quota errors with the configured status', function () {
    config(['tenancy.quotas.status' => 429]);

    expect((new QuotaExceeded('x'))->getStatusCode())->toBe(429);
});

it('meters per period and per tenant', function () {
    $a = planTenant('pro');
    $b = planTenant('pro');
    $metering = app(Metering::class);

    expect($metering->meter('api_calls', $a)->record(3))->toBe(3)
        ->and($metering->meter('api_calls', $a)->record())->toBe(4)
        ->and($metering->meter('api_calls', $b)->value())->toBe(0);

    $this->travel(1)->days();
    expect($metering->meter('api_calls', $a)->value())->toBe(0);
});

it('meters the current tenant by default', function () {
    $tenant = planTenant('free');

    Tenancy::run($tenant, fn () => app(Metering::class)->meter('exports')->record(2));

    expect(app(Metering::class)->meter('exports', $tenant)->value())->toBe(2);
});

it('fires threshold events once as usage crosses them', function () {
    Event::fake([UsageThresholdReached::class]);
    $meter = app(Metering::class)->meter('api_calls', planTenant('free'));

    $meter->record(7);
    $meter->record(1);
    $meter->record(1);
    $meter->record(1);

    Event::assertDispatchedTimes(UsageThresholdReached::class, 2);
    Event::assertDispatched(UsageThresholdReached::class, fn ($e) => $e->threshold === 80 && $e->value === 8);
    Event::assertDispatched(UsageThresholdReached::class, fn ($e) => $e->threshold === 100 && $e->value === 10);
});

it('exposes quota and meter helpers on the tenant', function () {
    $tenant = planTenant('free');
    $tenant->meter('api_calls')->record(4);

    expect($tenant->quota('api_calls')->remaining())->toBe(6);
});
