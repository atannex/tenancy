<?php

declare(strict_types=1);

use Atannex\Tenancy\Enums\TenantStatus;
use Atannex\Tenancy\Facades\Tenancy;
use Atannex\Tenancy\Models\Tenant;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    $this->acme = Tenant::factory()->withDomain('acme.example.com')->create(['slug' => 'acme']);

    Route::middleware('tenant')->get('/who', fn () => tenant()->getTenantSlug());
    Route::middleware('tenant.optional')->get('/maybe', fn () => tenant()?->getTenantSlug() ?? 'central');
    Route::middleware('tenant:path')->get('/t/{tenant}/who/{thing}', fn (string $thing) => tenant()->getTenantSlug().':'.$thing.':'.route('named', ['x' => 1]));
    Route::middleware('tenant:path')->get('/t/{tenant}/named', fn () => 'ok')->name('named');
    Route::middleware('tenant:header')->get('/api/who', fn () => tenant()->getTenantSlug());
    Route::middleware('central')->get('/admin', fn () => Tenancy::inCentral() ? 'central' : 'no');
});

it('initializes from the domain', function () {
    $this->get('http://acme.example.com/who')->assertOk()->assertSee('acme');
});

it('404s when no tenant matches', function () {
    $this->get('http://unknown.example.com/who')->assertNotFound();
});

it('lets optional routes run centrally', function () {
    $this->get('http://central.test/maybe')->assertSee('central');
    $this->get('http://acme.central.test/maybe')->assertSee('acme');
});

it('resolves from the path, hides the parameter and keeps it in generated urls', function () {
    $this->get('http://central.test/t/acme/who/box')
        ->assertOk()
        ->assertSee('acme:box:http://central.test/t/acme/named?x=1', false);
});

it('resolves from a header', function () {
    $this->getJson('http://api.central.test/api/who', ['X-Tenant' => 'acme'])->assertSee('acme');
});

it('refuses suspended tenants', function () {
    $this->acme->update(['status' => TenantStatus::Suspended]);
    $this->get('http://acme.example.com/who')->assertStatus(423);
});

it('refuses archived and pending tenants', function () {
    $this->acme->update(['status' => TenantStatus::Archived]);
    $this->get('http://acme.example.com/who')->assertStatus(410);

    $this->acme->update(['status' => TenantStatus::Pending]);
    $this->get('http://acme.example.com/who')->assertStatus(503);
});

it('can serve inactive tenants when require_active is off', function () {
    config(['tenancy.identification.require_active' => false]);
    $this->acme->update(['status' => TenantStatus::Suspended]);

    $this->get('http://acme.example.com/who')->assertOk();
});

it('keeps central routes on central domains', function () {
    $this->get('http://central.test/admin')->assertOk()->assertSee('central');
    $this->get('http://acme.example.com/admin')->assertNotFound();
});

it('allows central routes on any host when no central domains are set', function () {
    config(['tenancy.identification.central_domains' => []]);
    $this->get('http://anything.test/admin')->assertOk();
});
