<?php

declare(strict_types=1);

use Atannex\Tenancy\Exceptions\FeatureUnavailable;
use Atannex\Tenancy\Facades\Tenancy;
use Atannex\Tenancy\Plans\Features;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Route;

require_once __DIR__.'/../../Modules.php';

beforeEach(fn () => definePlans());

it('answers from the plan', function () {
    $tenant = planTenant('free');
    $features = app(Features::class);

    expect($features->active('reports', $tenant))->toBeTrue()
        ->and($features->active('sso', $tenant))->toBeFalse()
        ->and($features->all($tenant))->toBe(['reports']);
});

it('uses the current tenant by default', function () {
    $tenant = planTenant('pro');

    expect(Tenancy::run($tenant, fn () => app(Features::class)->active('sso')))->toBeTrue();
});

it('lets overrides win, until they expire', function () {
    $tenant = planTenant('free');
    $features = app(Features::class);

    $features->enable('sso', $tenant, now()->addDay());
    $features->disable('reports', $tenant);
    expect($features->active('sso', $tenant))->toBeTrue()
        ->and($features->active('reports', $tenant))->toBeFalse();

    $this->travel(2)->days();
    expect($features->active('sso', $tenant))->toBeFalse();

    $features->forget('reports', $tenant);
    expect($features->active('reports', $tenant))->toBeTrue();
});

it('guards routes with tenant.feature', function () {
    planTenant('free', ['slug' => 'freeco']);
    planTenant('pro', ['slug' => 'proco']);
    Route::middleware(['tenant', 'tenant.feature:sso'])->get('/sso', fn () => 'ok');

    $this->get('http://proco.central.test/sso')->assertOk();
    $this->get('http://freeco.central.test/sso')->assertForbidden();
});

it('throws FeatureUnavailable from assert()', function () {
    app(Features::class)->assert('sso', planTenant('free'));
})->throws(FeatureUnavailable::class);

it('adds a blade directive', function () {
    $tenant = planTenant('pro');

    $html = Tenancy::run($tenant, fn () => Blade::render("@tenantFeature('sso') yes @else no @endtenantFeature"));

    expect(trim($html))->toBe('yes');
});
