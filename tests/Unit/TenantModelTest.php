<?php

declare(strict_types=1);

use Atannex\Tenancy\Enums\DomainType;
use Atannex\Tenancy\Enums\IsolationTier;
use Atannex\Tenancy\Enums\TenantStatus;
use Atannex\Tenancy\Models\Tenant;

it('creates a tenant with ulid key and defaults', function () {
    $tenant = Tenant::create(['name' => 'Acme Corp']);

    expect($tenant->getTenantKey())->toHaveLength(26)
        ->and($tenant->getTenantSlug())->toBe('acme-corp')
        ->and($tenant->tenantStatus())->toBe(TenantStatus::Active)
        ->and($tenant->isolationTier())->toBe(IsolationTier::Shared);
});

it('stores app data and encrypted connection overrides', function () {
    $tenant = Tenant::create(['name' => 'Acme', 'data' => ['colour' => 'red']]);
    $tenant->forceFill(['connection' => ['password' => 's3cret']])->save();
    $fresh = $tenant->fresh();

    expect($fresh->data['colour'])->toBe('red')
        ->and($fresh->connection['password'])->toBe('s3cret')
        ->and(DB::table('tenants')->value('connection'))->not->toContain('s3cret');
});

it('rejects slugs that are not dns labels', function (string $slug) {
    Tenant::create(['name' => 'X', 'slug' => $slug]);
})->with(['Has Space', '-lead', 'trail-', 'under_score', str_repeat('a', 64)])
    ->throws(InvalidArgumentException::class);

it('rejects reserved slugs', function () {
    Tenant::create(['name' => 'WWW', 'slug' => 'www']);
})->throws(InvalidArgumentException::class, 'reserved');

it('owns domains with normalised hosts', function () {
    $tenant = Tenant::create(['name' => 'Acme']);
    $domain = $tenant->domains()->create(['host' => 'HTTPS://Acme.Example.com:8443/', 'type' => DomainType::Primary]);

    expect($domain->host)->toBe('acme.example.com')
        ->and($tenant->primaryDomain()?->is($domain))->toBeTrue()
        ->and($domain->isVerified())->toBeFalse();
});

it('builds tenants from the factory', function () {
    $tenant = Tenant::factory()->withDomain('shop.test')->create();

    expect($tenant->domains()->pluck('host')->all())->toBe(['shop.test']);
    expect(Tenant::factory()->suspended()->create()->tenantStatus())->toBe(TenantStatus::Suspended);
});
