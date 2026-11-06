<?php

declare(strict_types=1);

use Atannex\Tenancy\Events\TenancyEnded;
use Atannex\Tenancy\Events\TenancyInitialized;
use Atannex\Tenancy\Exceptions\NoTenantContext;
use Atannex\Tenancy\Exceptions\TenantNotFound;
use Atannex\Tenancy\Facades\Tenancy;
use Atannex\Tenancy\Models\Tenant;
use Atannex\Tenancy\Tests\Fixtures\RecordingBootstrapper;
use Illuminate\Support\Facades\Event;

beforeEach(function () {
    RecordingBootstrapper::$log = [];
    RecordingBootstrapper::$failFor = null;
    config(['tenancy.bootstrappers' => [RecordingBootstrapper::class]]);
    $this->a = Tenant::factory()->create(['slug' => 'alpha']);
    $this->b = Tenant::factory()->create(['slug' => 'beta']);
});

it('starts with no tenant', function () {
    expect(Tenancy::current())->toBeNull()
        ->and(Tenancy::initialized())->toBeFalse()
        ->and(tenant())->toBeNull();
});

it('initializes and ends a tenant, running bootstrappers', function () {
    Tenancy::initialize($this->a);
    expect(tenant()?->getTenantKey())->toBe($this->a->id);

    Tenancy::end();
    expect(Tenancy::current())->toBeNull()
        ->and(RecordingBootstrapper::$log)->toBe(['up:alpha', 'down']);
});

it('initializes by key or slug', function () {
    expect(Tenancy::initialize('beta')->getTenantKey())->toBe($this->b->id);
    Tenancy::end();
    expect(Tenancy::initialize($this->a->id)->getTenantSlug())->toBe('alpha');
});

it('throws for unknown tenants', function () {
    Tenancy::initialize('nope');
})->throws(TenantNotFound::class);

it('nests run() and restores the outer tenant', function () {
    Tenancy::initialize($this->a);

    $inner = Tenancy::run($this->b, fn ($t) => tenant()->getTenantSlug().'/'.$t->getTenantSlug());

    expect($inner)->toBe('beta/beta')
        ->and(tenant()->getTenantSlug())->toBe('alpha')
        ->and(RecordingBootstrapper::$log)->toBe(['up:alpha', 'down', 'up:beta', 'down', 'up:alpha']);
});

it('does not re-bootstrap when running the same tenant', function () {
    Tenancy::initialize($this->a);
    Tenancy::run($this->a, fn () => null);

    expect(RecordingBootstrapper::$log)->toBe(['up:alpha']);
});

it('restores context when the callback throws', function () {
    Tenancy::initialize($this->a);

    expect(fn () => Tenancy::run($this->b, fn () => throw new RuntimeException('boom')))
        ->toThrow(RuntimeException::class, 'boom');

    expect(tenant()->getTenantSlug())->toBe('alpha');
});

it('unwinds frames the callback forgot to end', function () {
    Tenancy::run($this->a, function () {
        Tenancy::initialize($this->b);
    });

    expect(Tenancy::current())->toBeNull();
});

it('restores the previous tenant when a bootstrapper fails', function () {
    Tenancy::initialize($this->a);
    RecordingBootstrapper::$failFor = 'beta';

    expect(fn () => Tenancy::initialize($this->b))->toThrow(RuntimeException::class);
    expect(tenant()->getTenantSlug())->toBe('alpha')
        ->and(RecordingBootstrapper::$log)->toBe(['up:alpha', 'down', 'up:alpha']);
});

it('enters an explicit central frame', function () {
    Tenancy::initialize($this->a);

    Tenancy::central(function () {
        expect(Tenancy::current())->toBeNull()->and(Tenancy::inCentral())->toBeTrue();
    });

    expect(tenant()->getTenantSlug())->toBe('alpha')->and(Tenancy::inCentral())->toBeFalse();
});

it('requires a tenant in currentOrFail', function () {
    Tenancy::currentOrFail();
})->throws(NoTenantContext::class);

it('dispatches lifecycle events', function () {
    Event::fake([TenancyInitialized::class, TenancyEnded::class]);

    Tenancy::run($this->a, fn () => null);

    Event::assertDispatched(TenancyInitialized::class, fn ($e) => $e->tenant->getTenantKey() === $this->a->id);
    Event::assertDispatched(TenancyEnded::class);
});

it('runs a callback for each active tenant', function () {
    Tenant::factory()->suspended()->create();
    $seen = [];

    $count = Tenancy::each(function ($tenant) use (&$seen) {
        $seen[] = tenant()->getTenantSlug();
    });

    expect($count)->toBe(2)->and($seen)->toEqualCanonicalizing(['alpha', 'beta'])
        ->and(Tenancy::current())->toBeNull();
});

it('resets between requests', function () {
    Tenancy::initialize($this->a);
    app()->terminate();

    expect(Tenancy::current())->toBeNull();
});
