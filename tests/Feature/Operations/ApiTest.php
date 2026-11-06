<?php

declare(strict_types=1);

use Atannex\Tenancy\Contracts\DnsResolver;
use Atannex\Tenancy\Enums\TenantStatus;
use Atannex\Tenancy\Http\ApiRoutes;
use Atannex\Tenancy\Models\Domain;
use Atannex\Tenancy\Models\Tenant;
use Atannex\Tenancy\Tests\Fixtures\FakeDns;

beforeEach(function () {
    config(['tenancy.api.middleware' => []]);
    ApiRoutes::register(app('router'));
    app()->bind(DnsResolver::class, FakeDns::class);
    $this->base = 'http://central.test/tenancy-api';
});

it('creates, lists and shows tenants', function () {
    $this->postJson("{$this->base}/tenants", ['name' => 'Api Co', 'domain' => 'api-co.test'])
        ->assertCreated()->assertJsonPath('data.slug', 'api-co')->assertJsonPath('data.status', 'active')
        ->assertJsonPath('data.domains.0.host', 'api-co.test')->assertJsonPath('data.domains.0.verified', false);

    $this->getJson("{$this->base}/tenants?search=api")->assertOk()->assertJsonPath('meta.total', 1);
    $this->getJson("{$this->base}/tenants/api-co")->assertOk()->assertJsonPath('data.name', 'Api Co');
    $this->getJson("{$this->base}/tenants/nope")->assertNotFound();
});

it('validates input', function () {
    $this->postJson("{$this->base}/tenants", ['slug' => 'Bad Slug'])->assertUnprocessable()->assertJsonValidationErrors(['name', 'slug']);
});

it('drives the lifecycle and answers conflicts with 409', function () {
    $tenant = Tenant::factory()->create(['slug' => 'life']);

    $this->postJson("{$this->base}/tenants/life/suspend", ['reason' => 'unpaid'])->assertOk()->assertJsonPath('data.status', 'suspended');
    $this->postJson("{$this->base}/tenants/life/restore")->assertStatus(409);
    $this->postJson("{$this->base}/tenants/life/reactivate")->assertOk();
    $this->postJson("{$this->base}/tenants/life/archive", ['grace_days' => 5])->assertOk()->assertJsonPath('data.status', 'archived');
    $this->postJson("{$this->base}/tenants/life/restore")->assertOk();

    expect($tenant->fresh()->status)->toBe(TenantStatus::Active);
});

it('manages domains', function () {
    Tenant::factory()->create(['slug' => 'dom']);

    $id = $this->postJson("{$this->base}/tenants/dom/domains", ['host' => 'dom.test'])
        ->assertCreated()->assertJsonPath('data.verification.name', '_atannex-tenancy.dom.test')->json('data.id');
    $this->postJson("{$this->base}/domains/{$id}/verify")->assertUnprocessable();

    FakeDns::$records['_atannex-tenancy.dom.test'] = [Domain::find($id)->verification_token];
    $this->postJson("{$this->base}/domains/{$id}/verify")->assertOk()->assertJsonPath('data.verified', true);
    $this->postJson("{$this->base}/domains/{$id}/primary")->assertOk()->assertJsonPath('data.type', 'primary');
    $this->getJson("{$this->base}/tenants/dom/domains")->assertJsonCount(1, 'data');
    $this->deleteJson("{$this->base}/domains/{$id}")->assertNoContent();
});

it('reports members and usage', function () {
    Tenant::factory()->create(['slug' => 'use']);

    $this->getJson("{$this->base}/tenants/use/members")->assertOk()->assertJsonPath('data', []);
    $this->getJson("{$this->base}/tenants/use/usage")->assertOk()->assertJsonPath('data', []);
});

it('only answers on central domains', function () {
    Tenant::factory()->withDomain('tenant-host.test')->create();

    $this->getJson('http://tenant-host.test/tenancy-api/tenants')->assertNotFound();
});
