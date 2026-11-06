<?php

declare(strict_types=1);

use Atannex\Tenancy\Enums\TenantStatus;
use Atannex\Tenancy\Facades\Tenancy;
use Atannex\Tenancy\Lifecycle\Maintenance;
use Atannex\Tenancy\Models\Tenant;
use Atannex\Tenancy\People\Members;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

require_once __DIR__.'/../Modules.php';

it('re-checks status when a tenant was set earlier in the request', function () {
    $tenant = Tenant::factory()->create(['status' => TenantStatus::Suspended]);
    Route::middleware('tenant')->get('/late', fn () => 'served');

    Tenancy::initialize($tenant);

    $this->get('http://central.test/late')->assertStatus(423);
});

it('re-checks maintenance when a tenant was set earlier in the request', function () {
    $tenant = Tenant::factory()->create();
    app(Maintenance::class)->down($tenant);
    Route::middleware('tenant')->get('/late', fn () => 'served');

    Tenancy::initialize($tenant->fresh());

    $this->get('http://central.test/late')->assertStatus(503);
});

it('indexes the tenant column on every driver', function () {
    Schema::create('indexed_rows', function (Blueprint $table) {
        $table->id();
        $table->tenant();
    });

    expect(collect(Schema::getIndexes('indexed_rows'))->contains(fn ($i) => $i['columns'] === ['tenant_id']))->toBeTrue();
});

it('blocks non-members who name a tenant by header when paired with tenant.member', function () {
    $tenant = Tenant::factory()->create(['slug' => 'private']);
    $member = moduleUser('Member', 'member@private.test');
    $outsider = moduleUser('Outsider', 'outsider@elsewhere.test');
    app(Members::class)->add($tenant, $member, 'member');
    Route::middleware(['web', 'tenant:header', 'auth', 'tenant.member'])->get('/api/data', fn () => 'secret');

    $this->actingAs($outsider)->getJson('http://api.central.test/api/data', ['X-Tenant' => 'private'])->assertForbidden();
    $this->actingAs($member)->getJson('http://api.central.test/api/data', ['X-Tenant' => 'private'])->assertOk()->assertSee('secret');
});
