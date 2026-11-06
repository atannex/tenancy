<?php

declare(strict_types=1);

use Atannex\Tenancy\Facades\Tenancy;
use Atannex\Tenancy\Http\Middleware\InitializeTenancy;
use Atannex\Tenancy\Integrations\Livewire\LivewireTenancy;
use Atannex\Tenancy\Models\Tenant;
use Atannex\Tenancy\Tests\Fixtures\Livewire\WhoAmI;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Livewire\LivewireServiceProvider;
use Livewire\Mechanisms\PersistentMiddleware\PersistentMiddleware;

beforeEach(function () {
    $this->app->register(LivewireServiceProvider::class);
    LivewireTenancy::register(config());
    Livewire::component('who-am-i', WhoAmI::class);

    $this->a = Tenant::factory()->withDomain('a.example.com')->create(['slug' => 'aa']);
    Tenant::factory()->create(['slug' => 'bb']);
});

/** Render the page, then send one Livewire update calling check() on its component. */
function livewireCall(string $pageUrl): string
{
    $html = test()->get($pageUrl)->assertOk()->getContent();
    preg_match('/wire:snapshot="([^"]+)"/', $html, $m);
    $origin = parse_url($pageUrl, PHP_URL_SCHEME).'://'.parse_url($pageUrl, PHP_URL_HOST);

    $response = test()->withHeaders(['X-Livewire' => '1'])->postJson($origin.app('livewire')->getUpdateUri(), [
        'components' => [['snapshot' => htmlspecialchars_decode($m[1]), 'updates' => [], 'calls' => [['path' => '', 'method' => 'check', 'params' => []]]]],
    ])->assertOk();

    return (string) $response->json('components.0.effects.html');
}

it('makes the tenancy middleware persistent', function () {
    expect(app(PersistentMiddleware::class)->getPersistentMiddleware())->toContain(InitializeTenancy::class);
});

it('runs updates in the tenant of the page on its domain', function () {
    Route::middleware(['web', 'tenant'])->get('/page', fn () => view()->make('livewire-page'));
    app('view')->getFinder()->addLocation(__DIR__.'/views');

    expect(livewireCall('http://a.example.com/page'))->toContain('who:aa');
});

it('runs updates in the tenant of the page identified by path', function () {
    Route::middleware(['web', 'tenant:path'])->get('/{tenant}/page', fn () => view()->make('livewire-page'));
    app('view')->getFinder()->addLocation(__DIR__.'/views');

    expect(livewireCall('http://central.test/bb/page'))->toContain('who:bb');
});

it('keeps central pages central', function () {
    Route::middleware(['web'])->get('/page', fn () => view()->make('livewire-page'));
    app('view')->getFinder()->addLocation(__DIR__.'/views');

    expect(livewireCall('http://central.test/page'))->toContain('who:central');
});

it('keeps temporary uploads on a central copy of the upload disk', function () {
    $root = config('filesystems.disks.local.root');

    expect(config('livewire.temporary_file_upload.disk'))->toBe(LivewireTenancy::UPLOAD_DISK)
        ->and(Tenancy::run($this->a, fn () => [config('filesystems.disks.'.LivewireTenancy::UPLOAD_DISK.'.root'), config('filesystems.disks.local.root')]))
        ->toBe([$root, $root.'/tenants/'.$this->a->getKey()]);

    Storage::disk(LivewireTenancy::UPLOAD_DISK)->put('livewire-tmp/probe.txt', 'x');
    expect(Tenancy::run($this->a, fn () => Storage::disk(LivewireTenancy::UPLOAD_DISK)->exists('livewire-tmp/probe.txt')))->toBeTrue();
    Storage::disk(LivewireTenancy::UPLOAD_DISK)->delete('livewire-tmp/probe.txt');
});

it('can add middleware to the update route', function () {
    config(['tenancy.integrations.livewire.update_middleware' => ['web', 'tenant.optional']]);
    LivewireTenancy::register(config());
    app('router')->getRoutes()->refreshNameLookups();

    $route = collect(app('router')->getRoutes()->getRoutes())->first(fn ($r) => str_ends_with((string) $r->getName(), 'livewire.update') && $r->getName() !== 'default-livewire.update');

    expect($route->middleware())->toContain('tenant.optional');
});
