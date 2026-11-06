<?php

declare(strict_types=1);

use Atannex\Tenancy\Bootstrappers\CacheBootstrapper;
use Atannex\Tenancy\Bootstrappers\ConfigBootstrapper;
use Atannex\Tenancy\Bootstrappers\FilesystemBootstrapper;
use Atannex\Tenancy\Bootstrappers\LogBootstrapper;
use Atannex\Tenancy\Bootstrappers\MailBootstrapper;
use Atannex\Tenancy\Bootstrappers\RedisBootstrapper;
use Atannex\Tenancy\Bootstrappers\ScoutBootstrapper;
use Atannex\Tenancy\Bootstrappers\SessionBootstrapper;
use Atannex\Tenancy\Bootstrappers\UrlBootstrapper;
use Atannex\Tenancy\Broadcasting\TenantChannel;
use Atannex\Tenancy\Facades\Tenancy;
use Atannex\Tenancy\Identification\ResolverCache;
use Atannex\Tenancy\Models\Tenant;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->a = Tenant::factory()->withDomain('a.example.com')->create(['slug' => 'aa', 'name' => 'Alpha', 'data' => ['mail' => ['from_address' => 'desk@alpha.test']]]);
    $this->b = Tenant::factory()->create(['slug' => 'bb', 'name' => 'Beta']);
});

it('gives each tenant its own cache', function () {
    config(['tenancy.bootstrappers' => [CacheBootstrapper::class]]);
    Cache::put('k', 'central');

    Tenancy::run($this->a, fn () => Cache::put('k', 'alpha'));
    Tenancy::run($this->b, fn () => Cache::put('k', 'beta'));

    expect(Cache::get('k'))->toBe('central')
        ->and(Tenancy::run($this->a, fn () => Cache::get('k')))->toBe('alpha')
        ->and(Tenancy::run($this->b, fn () => Cache::get('k')))->toBe('beta');
});

it('flushes only the current tenant cache', function () {
    config(['tenancy.bootstrappers' => [CacheBootstrapper::class]]);
    Cache::put('k', 'central');
    Tenancy::run($this->a, fn () => Cache::put('k', 'alpha'));
    Tenancy::run($this->b, fn () => Cache::put('k', 'beta'));

    Tenancy::run($this->a, fn () => Cache::flush());

    expect(Tenancy::run($this->a, fn () => Cache::get('k')))->toBeNull()
        ->and(Tenancy::run($this->b, fn () => Cache::get('k')))->toBe('beta')
        ->and(Cache::get('k'))->toBe('central');
});

it('namespaces cache locks and tags per tenant', function () {
    config(['tenancy.bootstrappers' => [CacheBootstrapper::class]]);

    $lockA = Tenancy::run($this->a, fn () => Cache::lock('job', 10)->get());
    $lockB = Tenancy::run($this->b, fn () => Cache::lock('job', 10)->get());
    Tenancy::run($this->a, fn () => Cache::tags(['t'])->put('x', 1));

    expect($lockA)->toBeTrue()->and($lockB)->toBeTrue()
        ->and(Tenancy::run($this->b, fn () => Cache::tags(['t'])->get('x')))->toBeNull()
        ->and(Tenancy::run($this->a, fn () => Cache::tags(['t'])->get('x')))->toBe(1);
});

it('keeps the resolver cache central while a tenant is current', function () {
    config(['tenancy.bootstrappers' => [CacheBootstrapper::class]]);

    Route::middleware('tenant')->get('/ping', function () {
        Cache::put('in-tenant', true);

        return app(ResolverCache::class)->remember('tenancy:probe', fn () => 'central-store');
    });

    $this->get('http://a.example.com/ping')->assertOk();

    expect(Cache::get('tenancy:domain:a.example.com'))->toBe($this->a->id);
});

it('gives each tenant its own folder on disks', function () {
    config(['tenancy.bootstrappers' => [FilesystemBootstrapper::class], 'filesystems.disks.public.url' => 'http://central.test/storage']);
    $root = config('filesystems.disks.local.root');

    Tenancy::run($this->a, fn () => Storage::disk('local')->put('report.txt', 'alpha'));

    expect(is_file($root.'/tenants/'.$this->a->id.'/report.txt'))->toBeTrue()
        ->and(Tenancy::run($this->b, fn () => Storage::disk('local')->exists('report.txt')))->toBeFalse()
        ->and(Tenancy::run($this->a, fn () => Storage::disk('public')->url('x.png')))->toBe('http://central.test/storage/tenants/'.$this->a->id.'/x.png')
        ->and(config('filesystems.disks.local.root'))->toBe($root);

    Storage::disk('local')->deleteDirectory('tenants');
});

it('scopes the session cookie', function () {
    config(['tenancy.bootstrappers' => [SessionBootstrapper::class]]);
    $cookie = config('session.cookie');

    $inA = Tenancy::run($this->a, fn () => config('session.cookie'));
    $inB = Tenancy::run($this->b, fn () => config('session.cookie'));

    expect($inA)->not->toBe($cookie)->and($inA)->not->toBe($inB)->and(config('session.cookie'))->toBe($cookie);
});

it('roots urls at the tenant domain', function () {
    config(['tenancy.bootstrappers' => [UrlBootstrapper::class]]);

    expect(Tenancy::run($this->a, fn () => url('/x')))->toBe('https://a.example.com/x')
        ->and(Tenancy::run($this->b, fn () => url('/x')))->toBe('https://bb.central.test/x')
        ->and(url('/x'))->toBe('http://localhost/x');
});

it('maps tenant attributes into config and back', function () {
    config(['tenancy.bootstrappers' => [ConfigBootstrapper::class], 'tenancy.config_map' => ['app.name' => 'name', 'services.x' => 'data.missing']]);
    config(['app.name' => 'Central', 'services.x' => 'keep']);

    expect(Tenancy::run($this->a, fn () => [config('app.name'), config('services.x')]))->toBe(['Alpha', 'keep'])
        ->and(config('app.name'))->toBe('Central');
});

it('sets the mail sender from the tenant', function () {
    config(['tenancy.bootstrappers' => [MailBootstrapper::class], 'mail.from' => ['address' => 'hq@central.test', 'name' => 'HQ']]);

    expect(Tenancy::run($this->a, fn () => config('mail.from')))->toBe(['address' => 'desk@alpha.test', 'name' => 'Alpha'])
        ->and(Tenancy::run($this->b, fn () => config('mail.from')))->toBe(['address' => 'hq@central.test', 'name' => 'Beta'])
        ->and(config('mail.from.name'))->toBe('HQ');
});

it('adds the tenant to the log context', function () {
    config(['tenancy.bootstrappers' => [LogBootstrapper::class]]);

    expect(Tenancy::run($this->a, fn () => Context::get('tenant_slug')))->toBe('aa')
        ->and(Context::has('tenant_slug'))->toBeFalse();
});

it('prefixes scout indexes', function () {
    config(['tenancy.bootstrappers' => [ScoutBootstrapper::class], 'scout.prefix' => 'app_']);

    expect(Tenancy::run($this->a, fn () => config('scout.prefix')))->toBe('app_tenant_'.$this->a->id.'_')
        ->and(config('scout.prefix'))->toBe('app_');
});

it('prefixes redis keys per tenant', function () {
    try {
        Redis::connection()->ping();
    } catch (Throwable) {
        $this->markTestSkipped('No Redis server.');
    }

    config(['tenancy.bootstrappers' => [RedisBootstrapper::class], 'tenancy.redis.connections' => ['default']]);
    Tenancy::run($this->a, fn () => Redis::set('atx-tenancy-test', 'alpha'));

    expect(Redis::get('atx-tenancy-test'))->toBeNull()
        ->and(Tenancy::run($this->a, fn () => Redis::get('atx-tenancy-test')))->toBe('alpha');

    Tenancy::run($this->a, fn () => Redis::del('atx-tenancy-test'));
})->skip(! extension_loaded('redis'), 'phpredis missing');

it('names broadcast channels per tenant', function () {
    $channel = Tenancy::run($this->a, fn () => new TenantChannel('orders'));

    expect($channel->name)->toBe('private-tenant.'.$this->a->id.'.orders');
});
