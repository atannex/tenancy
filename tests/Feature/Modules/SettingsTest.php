<?php

declare(strict_types=1);

use Atannex\Tenancy\Facades\Tenancy;
use Atannex\Tenancy\Plans\Settings;

require_once __DIR__.'/../../Modules.php';

it('falls back to config defaults', function () {
    config(['tenancy.settings.defaults' => ['locale' => 'en', 'theme' => 'light']]);

    expect(app(Settings::class)->for(planTenant())->get('locale'))->toBe('en')
        ->and(app(Settings::class)->for(planTenant())->get('missing', 'x'))->toBe('x');
});

it('stores typed values per tenant', function () {
    $a = planTenant();
    $b = planTenant();

    app(Settings::class)->for($a)->set('limits', ['max' => 3]);
    app(Settings::class)->for($a)->set('enabled', true);

    expect(app(Settings::class)->for($a)->get('limits'))->toBe(['max' => 3])
        ->and(app(Settings::class)->for($a)->get('enabled'))->toBeTrue()
        ->and(app(Settings::class)->for($b)->get('enabled'))->toBeNull();
});

it('lists settings over defaults and forgets them', function () {
    config(['tenancy.settings.defaults' => ['locale' => 'en']]);
    $tenant = planTenant();
    $settings = app(Settings::class)->for($tenant);

    $settings->set('locale', 'fr');
    expect($settings->all())->toBe(['locale' => 'fr']);

    $settings->forget('locale');
    expect($settings->get('locale'))->toBe('en');
});

it('serves the current tenant and the tenant helper', function () {
    $tenant = planTenant();
    $tenant->settings()->set('colour', 'red');

    expect(Tenancy::run($tenant, fn () => app(Settings::class)->for()->get('colour')))->toBe('red');
});
