<?php

declare(strict_types=1);

use Atannex\Tenancy\Exceptions\NoTenantContext;
use Atannex\Tenancy\Exceptions\TenantMismatch;
use Atannex\Tenancy\Facades\Tenancy;
use Atannex\Tenancy\Identification\ResolverCache;
use Atannex\Tenancy\Identification\ResolverPipeline;
use Atannex\Tenancy\Models\Tenant;
use Atannex\Tenancy\Tests\Fixtures\Models\Project;
use Atannex\Tenancy\Tests\Fixtures\RecordingBootstrapper;
use Illuminate\Config\Repository;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Http\Request;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Routing\Exceptions\UrlGenerationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

function hostTenant(string $url): ?string
{
    return app(ResolverPipeline::class)->resolve(Request::create($url))?->getTenantSlug();
}

beforeEach(function () {
    RecordingBootstrapper::$log = [];
    RecordingBootstrapper::$failFor = null;
    RecordingBootstrapper::$failRevert = false;
    $this->a = Tenant::factory()->create(['slug' => 'aa']);
    $this->b = Tenant::factory()->create(['slug' => 'bb']);
});

// Critical 1
it('never resolves unverified custom domains', function () {
    $this->b->domains()->create(['host' => 'shop.example.com']);

    expect(hostTenant('http://shop.example.com/'))->toBeNull();
});

it('refuses domains on or under a central domain', function (string $host) {
    $this->b->domains()->create(['host' => $host, 'verified_at' => now()]);
})->with(['central.test', 'aa.central.test'])->throws(InvalidArgumentException::class, 'central');

// Important 2
it('rejects slugs that look like tenant keys', function () {
    $this->b->update(['slug' => strtolower($this->a->id)]);
})->throws(InvalidArgumentException::class, 'key');

it('prefers an exact key match over a slug', function () {
    DB::table('tenants')->where('id', $this->b->id)->update(['slug' => strtolower($this->a->id)]);

    expect(Tenancy::find($this->a->id)->getTenantSlug())->toBe('aa');
});

// Important 3
it('refuses to save or delete a model from another tenant', function () {
    $project = Tenancy::run($this->a, fn () => Project::create(['name' => 'A', 'slug' => 'a']));

    expect(fn () => Tenancy::run($this->b, fn () => $project->update(['name' => 'x'])))->toThrow(TenantMismatch::class)
        ->and(fn () => Tenancy::run($this->b, fn () => $project->delete()))->toThrow(TenantMismatch::class)
        ->and(fn () => $project->delete())->toThrow(NoTenantContext::class);

    expect(Tenancy::central(fn () => Project::find($project->id)->name))->toBe('A');
});

// Important 4
it('refuses bulk updates of the tenant column', function () {
    Tenancy::run($this->a, fn () => Project::create(['name' => 'A', 'slug' => 'a']));

    Tenancy::run($this->a, fn () => Project::query()->update(['tenant_id' => $this->b->id]));
})->throws(TenantMismatch::class);

it('stamps and guards bulk inserts', function () {
    Tenancy::run($this->a, fn () => Project::insert([['name' => 'x', 'slug' => 'x']]));
    expect(Tenancy::central(fn () => Project::where('slug', 'x')->value('tenant_id')))->toBe($this->a->id);

    Tenancy::run($this->b, fn () => Project::insert([['name' => 'y', 'slug' => 'y', 'tenant_id' => $this->a->id]]));
})->throws(TenantMismatch::class);

it('requires upserts to be keyed by tenant', function () {
    Tenancy::run($this->a, fn () => Project::upsert([['name' => 'x', 'slug' => 'x']], ['slug'], ['name']));
})->throws(TenantMismatch::class);

it('allows tenant-keyed upserts', function () {
    Tenancy::run($this->a, fn () => Project::upsert([['name' => 'x', 'slug' => 'x']], ['tenant_id', 'slug'], ['name']));

    expect(Tenancy::run($this->a, fn () => Project::count()))->toBe(1);
});

// Important 5
it('does not leak path url defaults after tenancy ends', function () {
    Route::middleware('tenant:path')->get('/{tenant}/home', fn () => route('home'))->name('home');

    $this->get('http://central.test/aa/home')->assertSee('/aa/home', false);
    Tenancy::reset();

    route('home');
})->throws(UrlGenerationException::class);

it('points path url defaults at a nested tenant', function () {
    Route::middleware('tenant:path')->get('/{tenant}/home', fn () => route('home').'|'.Tenancy::run('bb', fn () => route('home')).'|'.route('home'))->name('home');

    $this->get('http://central.test/aa/home')->assertSee('/aa/home|http://central.test/bb/home|http://central.test/aa/home', false);
});

// Important 6
it('reads the live config, not the one it was built with', function () {
    Tenancy::current();
    app()->instance('config', new Repository(array_replace_recursive(config()->all(), ['tenancy' => ['strict' => false]])));

    expect(Tenancy::strict())->toBeFalse();
});

// Important 7
it('resets between queue jobs', function () {
    Tenancy::initialize($this->a);
    event(new JobProcessed('sync', Mockery::mock(Job::class)));

    expect(Tenancy::current())->toBeNull();
});

// Important 8
it('keeps context consistent when a revert fails', function () {
    config(['tenancy.bootstrappers' => [RecordingBootstrapper::class]]);
    Tenancy::initialize($this->a);
    Tenancy::initialize($this->b);
    RecordingBootstrapper::$failRevert = true;

    expect(fn () => Tenancy::end())->toThrow(RuntimeException::class, 'revert failed');
    expect(tenant()->getTenantSlug())->toBe('aa')
        ->and(end(RecordingBootstrapper::$log))->toBe('up:aa');
});

it('does not mask the callback exception when unwinding fails', function () {
    config(['tenancy.bootstrappers' => [RecordingBootstrapper::class]]);

    expect(fn () => Tenancy::run($this->a, function () {
        RecordingBootstrapper::$failRevert = true;
        throw new LogicException('boom');
    }))->toThrow(LogicException::class, 'boom');

    expect(Tenancy::current())->toBeNull();
});

// Important 9
it('forgets cached hosts only after the transaction commits', function () {
    $domain = $this->a->domains()->create(['host' => 'move.example.com', 'verified_at' => now()]);
    expect(hostTenant('http://move.example.com/'))->toBe('aa');

    DB::transaction(function () use ($domain) {
        $domain->update(['tenant_id' => $this->b->id]);
        // a concurrent request re-caches the old owner before commit
        Cache::put(ResolverCache::domainKey('move.example.com'), $this->a->id, 3600);
    });

    expect(hostTenant('http://move.example.com/'))->toBe('bb');
});
