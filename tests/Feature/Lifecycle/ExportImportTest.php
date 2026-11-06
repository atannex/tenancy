<?php

declare(strict_types=1);

use Atannex\Tenancy\Bootstrappers\DatabaseBootstrapper;
use Atannex\Tenancy\Bootstrappers\FilesystemBootstrapper;
use Atannex\Tenancy\Enums\IsolationTier;
use Atannex\Tenancy\Facades\Tenancy;
use Atannex\Tenancy\Isolation\TenantDatabase;
use Atannex\Tenancy\Lifecycle\Steps\CreateDatabase;
use Atannex\Tenancy\Lifecycle\Steps\MigrateDatabase;
use Atannex\Tenancy\Lifecycle\TenantLifecycle;
use Atannex\Tenancy\Models\Tenant;
use Atannex\Tenancy\Tests\Fixtures\Models\Note;
use Atannex\Tenancy\Tests\Fixtures\Models\Project;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    config([
        'tenancy.bootstrappers' => [DatabaseBootstrapper::class, FilesystemBootstrapper::class],
        'tenancy.provisioning.steps' => [CreateDatabase::class, MigrateDatabase::class],
    ]);
    $this->zip = sys_get_temp_dir().'/atx-export-'.uniqid().'.zip';
});

afterEach(function () {
    @unlink($this->zip);
    Storage::disk('local')->deleteDirectory('tenants');
});

it('moves a shared tenant out and back in with its files', function () {
    $source = Tenancy::create(['name' => 'Source', 'slug' => 'source']);
    Tenancy::run($source, function () {
        Project::create(['name' => 'One', 'slug' => 'one']);
        Project::create(['name' => 'Two', 'slug' => 'two']);
        Storage::disk('local')->put('docs/a.txt', 'alpha');
    });

    $this->artisan('tenants:export', ['tenant' => 'source', '--path' => $this->zip])->assertSuccessful();
    // Shared-tier rows keep their primary keys, so the source leaves first (moving platforms).
    app(TenantLifecycle::class)->archive($source, 0);
    app(TenantLifecycle::class)->purge($source->fresh());

    $this->artisan('tenants:import', ['path' => $this->zip])->assertSuccessful();

    $copy = Tenant::where('slug', 'source')->firstOrFail();
    expect($copy->id)->not->toBe($source->id)
        ->and(Tenancy::run($copy, fn () => Project::orderBy('slug')->pluck('name')->all()))->toBe(['One', 'Two'])
        ->and(Tenancy::run($copy, fn () => Storage::disk('local')->get('docs/a.txt')))->toBe('alpha');
});

it('rolls an import back when rows collide', function () {
    $source = Tenancy::create(['name' => 'Source', 'slug' => 'source']);
    Tenancy::run($source, fn () => Project::create(['name' => 'One', 'slug' => 'one']));
    $this->artisan('tenants:export', ['tenant' => 'source', '--path' => $this->zip])->assertSuccessful();

    $this->artisan('tenants:import', ['path' => $this->zip, '--slug' => 'clash'])->assertFailed();

    expect(Tenant::where('slug', 'clash')->exists())->toBeFalse();
});

it('exports a shared tenant and only its rows', function () {
    $source = Tenancy::create(['name' => 'Source', 'slug' => 'source']);
    $other = Tenancy::create(['name' => 'Other', 'slug' => 'other']);
    Tenancy::run($source, fn () => Project::create(['name' => 'Mine', 'slug' => 'mine']));
    Tenancy::run($other, fn () => Project::create(['name' => 'Theirs', 'slug' => 'theirs']));

    $this->artisan('tenants:export', ['tenant' => 'source', '--path' => $this->zip])->assertSuccessful();

    $zip = new ZipArchive;
    $zip->open($this->zip);
    $manifest = json_decode($zip->getFromName('manifest.json'), true);

    expect($manifest['tables']['projects'])->toBe(1)
        ->and($zip->getFromName('tables/projects.jsonl'))->toContain('Mine')->not->toContain('Theirs')
        ->and($manifest['tenant'])->not->toHaveKey('connection');
});

it('round-trips a dedicated tenant into a new database', function () {
    $source = Tenancy::create(['name' => 'Dedicated', 'slug' => 'ded', 'isolation' => IsolationTier::Database]);
    Tenancy::run($source, function () {
        Note::create(['body' => 'first']);
        Storage::disk('local')->put('a.txt', 'alpha');
    });

    $this->artisan('tenants:export', ['tenant' => 'ded', '--path' => $this->zip])->assertSuccessful();
    $this->artisan('tenants:import', ['path' => $this->zip, '--slug' => 'ded-copy'])->assertSuccessful();

    $copy = Tenant::where('slug', 'ded-copy')->firstOrFail();
    expect(Tenancy::run($copy, fn () => Note::first()?->body))->toBe('first')
        ->and(Tenancy::run($copy, fn () => Note::first()->tenant_id))->toBe($copy->id)
        ->and(Tenancy::run($copy, fn () => Storage::disk('local')->get('a.txt')))->toBe('alpha');

    foreach ([$source, $copy] as $tenant) {
        app(TenantLifecycle::class)->archive($tenant->fresh(), 0);
        app(TenantLifecycle::class)->purge($tenant->fresh());
    }

    expect(app(TenantDatabase::class)->exists($copy))->toBeFalse();
});

it('refuses files that are not exports', function () {
    file_put_contents($this->zip, 'nope');
    $this->artisan('tenants:import', ['path' => $this->zip])->assertFailed();
});
