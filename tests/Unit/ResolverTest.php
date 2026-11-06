<?php

declare(strict_types=1);

use Atannex\Tenancy\Events\TenantResolved;
use Atannex\Tenancy\Identification\ResolverPipeline;
use Atannex\Tenancy\Models\Tenant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

function resolveFor(string $url, array $names = [], array $headers = []): ?string
{
    $request = Request::create($url, server: collect($headers)->mapWithKeys(fn ($v, $k) => ['HTTP_'.strtoupper(str_replace('-', '_', $k)) => $v])->all());

    return app(ResolverPipeline::class)->resolve($request, $names)?->getTenantSlug();
}

beforeEach(function () {
    $this->acme = Tenant::factory()->withDomain('acme.example.com')->create(['slug' => 'acme']);
});

it('resolves by domain', function () {
    expect(resolveFor('http://acme.example.com/'))->toBe('acme');
});

it('normalises hosts', function () {
    expect(resolveFor('http://ACME.Example.com:8080/', ['domain']))->toBe('acme');
});

it('resolves by subdomain of a central domain', function () {
    expect(resolveFor('http://acme.central.test/'))->toBe('acme');
});

it('does not resolve nested subdomains or the central domain itself', function () {
    expect(resolveFor('http://x.acme.central.test/'))->toBeNull()
        ->and(resolveFor('http://central.test/'))->toBeNull();
});

it('resolves by header using key or slug', function () {
    expect(resolveFor('http://api.test/', ['header'], ['X-Tenant' => 'acme']))->toBe('acme')
        ->and(resolveFor('http://api.test/', ['header'], ['X-Tenant' => $this->acme->id]))->toBe('acme');
});

it('tries resolvers in order and reports which one matched', function () {
    Event::fake([TenantResolved::class]);

    resolveFor('http://acme.central.test/', ['domain', 'subdomain']);

    Event::assertDispatched(TenantResolved::class, fn ($e) => $e->resolver === 'subdomain');
});

it('caches host lookups', function () {
    resolveFor('http://acme.example.com/');

    DB::enableQueryLog();
    resolveFor('http://acme.example.com/');

    $domainQueries = collect(DB::getQueryLog())->filter(fn ($q) => str_contains($q['query'], 'tenant_domains'));
    expect($domainQueries)->toBeEmpty();
});

it('forgets cached hosts when a domain changes', function () {
    resolveFor('http://acme.example.com/');
    $other = Tenant::factory()->create(['slug' => 'other']);

    $this->acme->domains()->first()->update(['tenant_id' => $other->id]);
    expect(resolveFor('http://acme.example.com/'))->toBe('other');

    $other->domains()->first()->delete();
    expect(resolveFor('http://acme.example.com/'))->toBeNull();
});

it('forgets cached slugs when a tenant slug changes', function () {
    resolveFor('http://acme.central.test/');
    $this->acme->update(['slug' => 'acme-2']);

    expect(resolveFor('http://acme.central.test/'))->toBeNull()
        ->and(resolveFor('http://acme-2.central.test/'))->toBe('acme-2');
});

it('ignores soft-deleted tenants', function () {
    resolveFor('http://acme.example.com/');
    $this->acme->delete();

    expect(resolveFor('http://acme.example.com/'))->toBeNull()
        ->and(resolveFor('http://acme.central.test/'))->toBeNull();
});

it('rejects unknown resolver names', function () {
    resolveFor('http://acme.example.com/', ['carrier-pigeon']);
})->throws(InvalidArgumentException::class, 'carrier-pigeon');
