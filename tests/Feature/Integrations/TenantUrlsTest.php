<?php

declare(strict_types=1);

use Atannex\Tenancy\Bootstrappers\UrlBootstrapper;
use Atannex\Tenancy\Facades\Tenancy;
use Atannex\Tenancy\Models\Tenant;
use Illuminate\Routing\Exceptions\UrlGenerationException;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    $this->a = Tenant::factory()->withDomain('a.example.com')->create(['slug' => 'aa']);
    $this->b = Tenant::factory()->create(['slug' => 'bb']);
    Route::get('/{tenant}/home', fn () => 'home')->name('home');
    Route::get('/dash', fn () => 'dash')->name('dash');
});

it('fills the path parameter outside requests when the path resolver is configured', function () {
    config(['tenancy.identification.resolvers' => ['path']]);

    expect(Tenancy::run($this->b, fn () => route('home', [], false)))->toBe('/bb/home')
        ->and(Tenancy::run($this->a, fn () => Tenancy::run($this->b, fn () => route('home', [], false)).'|'.route('home', [], false)))->toBe('/bb/home|/aa/home');

    route('home');
})->throws(UrlGenerationException::class);

it('can be turned on without the path resolver', function () {
    config(['tenancy.identification.path_defaults' => true]);

    expect(Tenancy::run($this->b, fn () => route('home', [], false)))->toBe('/bb/home');
});

it('leaves route defaults alone for non-path apps', function () {
    Tenancy::run($this->b, fn () => route('home'));
})->throws(UrlGenerationException::class);

it('roots links built in jobs and commands at the tenant host by default', function () {
    expect(config('tenancy.bootstrappers'))->toContain(UrlBootstrapper::class)
        ->and(Tenancy::run($this->a, fn () => url('/x')))->toBe('https://a.example.com/x')
        ->and(url('/x'))->toBe('http://localhost/x');
});

it('keeps a request on the host it arrived on', function () {
    Route::middleware('tenant')->get('/where', fn () => url('/x'));

    $this->get('http://a.example.com/where')->assertSee('http://a.example.com/x', false);
});

it('can force the tenant root inside requests too', function () {
    config(['tenancy.url.requests' => true]);
    Route::middleware('tenant')->get('/where', fn () => url('/x'));

    $this->get('http://aa.central.test/where')->assertSee('https://a.example.com/x', false);
});

it('links to any tenant with tenant_route and tenant_url', function () {
    expect(tenant_route($this->a, 'dash'))->toBe('https://a.example.com/dash')
        ->and(tenant_route('bb', 'dash'))->toBe('https://bb.central.test/dash')
        ->and(tenant_route('bb', 'home', [], false))->toBe('/bb/home')
        ->and(tenant_url($this->a, 'files/1'))->toBe('https://a.example.com/files/1')
        ->and(Tenancy::run($this->a, fn () => tenant_route($this->b, 'dash')))->toBe('https://bb.central.test/dash');
});

it('roots path tenants without a host at the app url', function () {
    config(['tenancy.identification.resolvers' => ['path'], 'app.url' => 'https://app.test']);

    expect(tenant_route('bb', 'home'))->toBe('https://app.test/bb/home');
});

it('lets domain-constrained routes build their own host', function () {
    Route::domain('{tenant}.central.test')->get('/d', fn () => 'd')->name('sub');

    expect(tenant_route('bb', 'sub'))->toBe('http://bb.central.test/d');
});
