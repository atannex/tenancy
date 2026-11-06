<?php

declare(strict_types=1);

use Atannex\Tenancy\Facades\Tenancy;
use Atannex\Tenancy\Testing\InteractsWithTenancy;
use Atannex\Tenancy\Tests\Fixtures\Models\LeakyNote;
use Atannex\Tenancy\Tests\Fixtures\Models\Project;
use PHPUnit\Framework\AssertionFailedError;

uses(InteractsWithTenancy::class);

it('creates and acts as a tenant', function () {
    $tenant = $this->createTenant(['name' => 'Helper Co']);
    $this->actingAsTenant($tenant);

    expect(tenant()->getTenantSlug())->toBe('helper-co');
});

it('proves a model is isolated', function () {
    $this->assertIsolated(Project::class, fn () => Project::create(['name' => 'x', 'slug' => 'x']));

    expect(Tenancy::current())->toBeNull();
});

it('fails for a model that leaks', function () {
    $this->assertIsolated(LeakyNote::class, fn () => LeakyNote::create(['tenant_id' => tenant()->getTenantKey(), 'body' => 'x']));
})->throws(AssertionFailedError::class);
