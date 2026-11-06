<?php

declare(strict_types=1);

use Atannex\Tenancy\Bootstrappers\CacheBootstrapper;
use Atannex\Tenancy\Bootstrappers\DatabaseBootstrapper;
use Atannex\Tenancy\Bootstrappers\FilesystemBootstrapper;
use Atannex\Tenancy\Enums\IsolationTier;
use Atannex\Tenancy\Enums\StepStatus;
use Atannex\Tenancy\Enums\TenantStatus;
use Atannex\Tenancy\Events\ProvisioningFailed;
use Atannex\Tenancy\Events\TenantArchived;
use Atannex\Tenancy\Events\TenantProvisioned;
use Atannex\Tenancy\Events\TenantPurged;
use Atannex\Tenancy\Events\TenantSuspended;
use Atannex\Tenancy\Exceptions\IllegalTransition;
use Atannex\Tenancy\Facades\Tenancy;
use Atannex\Tenancy\Isolation\TenantDatabase;
use Atannex\Tenancy\Jobs\ProvisionTenant;
use Atannex\Tenancy\Lifecycle\Steps\CreateDatabase;
use Atannex\Tenancy\Lifecycle\Steps\MigrateDatabase;
use Atannex\Tenancy\Lifecycle\TenantLifecycle;
use Atannex\Tenancy\Models\ProvisioningStep;
use Atannex\Tenancy\Models\Tenant;
use Atannex\Tenancy\Tests\Fixtures\FlakyStep;
use Atannex\Tenancy\Tests\Fixtures\Models\Note;
use Atannex\Tenancy\Tests\Fixtures\Models\Project;
use Atannex\Tenancy\Tests\Fixtures\RecordingStep;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    FlakyStep::$failures = 0;
    FlakyStep::$log = [];
    config(['tenancy.provisioning.backoff_ms' => 0, 'tenancy.provisioning.steps' => [RecordingStep::class, FlakyStep::class]]);
    $this->lifecycle = app(TenantLifecycle::class);
});

it('creates and provisions a tenant through its steps', function () {
    Event::fake([TenantProvisioned::class]);

    $tenant = Tenancy::create(['name' => 'Acme'], ['colour' => 'red']);

    expect($tenant->status)->toBe(TenantStatus::Active)
        ->and(FlakyStep::$log)->toBe(['record:red', 'handle'])
        ->and($tenant->data['provisioning'] ?? null)->toBeNull()
        ->and(ProvisioningStep::where('tenant_id', $tenant->id)->pluck('status')->all())->toBe([StepStatus::Done, StepStatus::Done]);
    Event::assertDispatched(TenantProvisioned::class);
});

it('retries a flaky step', function () {
    FlakyStep::$failures = 2;

    $tenant = Tenancy::create(['name' => 'Acme']);

    expect($tenant->status)->toBe(TenantStatus::Active)
        ->and(ProvisioningStep::where('step', FlakyStep::class)->value('attempts'))->toBe(3);
});

it('rolls back and fails after the last try, then retries from scratch', function () {
    Event::fake([ProvisioningFailed::class]);
    FlakyStep::$failures = 3;

    $tenant = Tenancy::create(['name' => 'Acme']);

    expect($tenant->status)->toBe(TenantStatus::Failed)
        ->and(FlakyStep::$log)->toBe(['record:-', 'rollback', 'unrecord']);
    Event::assertDispatched(ProvisioningFailed::class, fn ($e) => $e->step === FlakyStep::class && $e->error === 'flaky');

    $this->artisan('tenants:retry', ['tenant' => $tenant->slug])->assertSuccessful();
    expect($tenant->fresh()->status)->toBe(TenantStatus::Active);
});

it('resumes at the failed step when rollback is off', function () {
    config(['tenancy.provisioning.rollback_on_failure' => false]);
    FlakyStep::$failures = 3;
    $tenant = Tenancy::create(['name' => 'Acme']);
    FlakyStep::$log = [];

    $this->artisan('tenants:retry', ['tenant' => $tenant->slug])->assertSuccessful();

    expect(FlakyStep::$log)->toBe(['handle']);
});

it('provisions on the queue when asked', function () {
    Queue::fake();
    config(['tenancy.provisioning.queue' => true]);

    $tenant = Tenancy::create(['name' => 'Acme']);

    expect($tenant->status)->toBe(TenantStatus::Pending);
    Queue::assertPushed(ProvisionTenant::class, fn ($job) => $job->tenant === $tenant->id);
});

it('provisions a dedicated database end to end', function () {
    config(['tenancy.provisioning.steps' => [CreateDatabase::class, MigrateDatabase::class], 'tenancy.bootstrappers' => [DatabaseBootstrapper::class]]);

    $tenant = Tenancy::create(['name' => 'Dedicated', 'isolation' => IsolationTier::Database]);
    Tenancy::run($tenant, fn () => Note::create(['body' => 'hello']));

    expect(Tenancy::run($tenant, fn () => Note::count()))->toBe(1);

    $this->lifecycle->archive($tenant);
    $this->lifecycle->purge($tenant->fresh());
    expect(app(TenantDatabase::class)->exists($tenant))->toBeFalse();
});

it('guards transitions', function () {
    $tenant = Tenant::factory()->create();

    $this->lifecycle->restore($tenant);
})->throws(IllegalTransition::class);

it('suspends, reactivates, archives and restores', function () {
    Event::fake([TenantSuspended::class, TenantArchived::class]);
    $tenant = Tenant::factory()->create();

    $this->lifecycle->suspend($tenant, 'unpaid');
    expect($tenant->status)->toBe(TenantStatus::Suspended)->and($tenant->data['suspension_reason'])->toBe('unpaid');
    Event::assertDispatched(TenantSuspended::class, fn ($e) => $e->reason === 'unpaid');

    $this->lifecycle->reactivate($tenant);
    $this->lifecycle->archive($tenant, 7);
    expect($tenant->purge_after->isSameDay(now()->addDays(7)))->toBeTrue();

    $this->lifecycle->restore($tenant);
    expect($tenant->fresh()->status)->toBe(TenantStatus::Active)->and($tenant->purge_after)->toBeNull();
});

it('purges a shared tenant: rows, files, cache and the tenant', function () {
    Event::fake([TenantPurged::class]);
    config(['tenancy.bootstrappers' => [CacheBootstrapper::class, FilesystemBootstrapper::class]]);
    $doomed = Tenant::factory()->create();
    $kept = Tenant::factory()->create();

    foreach ([$doomed, $kept] as $tenant) {
        Tenancy::run($tenant, function () {
            Project::create(['name' => 'p', 'slug' => 'p']);
            Storage::disk('local')->put('f.txt', 'x');
            Cache::put('k', 'v');
        });
    }

    $this->lifecycle->archive($doomed);
    $this->artisan('tenants:purge', ['tenant' => $doomed->slug, '--force' => true])->assertSuccessful();

    expect(Tenant::find($doomed->id))->toBeNull()
        ->and(Tenancy::central(fn () => Project::count()))->toBe(1)
        ->and(Storage::disk('local')->exists('tenants/'.$doomed->id.'/f.txt'))->toBeFalse()
        ->and(Storage::disk('local')->exists('tenants/'.$kept->id.'/f.txt'))->toBeTrue()
        ->and(Tenancy::run($kept, fn () => Cache::get('k')))->toBe('v');
    Event::assertDispatched(TenantPurged::class, fn ($e) => $e->tenant === $doomed->id);

    Storage::disk('local')->deleteDirectory('tenants');
});

it('refuses to purge a tenant that is not archived', function () {
    $this->lifecycle->purge(Tenant::factory()->create());
})->throws(IllegalTransition::class);

it('purges only tenants whose grace period is over', function () {
    $due = Tenant::factory()->create();
    $later = Tenant::factory()->create();
    $this->lifecycle->archive($due, 0);
    $this->lifecycle->archive($later, 10);
    $this->travel(1)->minutes();

    $this->artisan('tenants:purge', ['--due' => true])->assertSuccessful();

    expect(Tenant::find($due->id))->toBeNull()->and(Tenant::find($later->id))->not->toBeNull();
});

it('drives the lifecycle from the console', function () {
    $tenant = Tenant::factory()->create(['slug' => 'cli']);

    $this->artisan('tenants:suspend', ['tenant' => 'cli', '--reason' => 'abuse'])->assertSuccessful();
    $this->artisan('tenants:reactivate', ['tenant' => 'cli'])->assertSuccessful();
    $this->artisan('tenants:archive', ['tenant' => 'cli', '--grace' => 3])->assertSuccessful();
    $this->artisan('tenants:restore', ['tenant' => 'cli'])->assertSuccessful();

    expect($tenant->fresh()->status)->toBe(TenantStatus::Active);
});

it('fakes provisioning for app tests', function () {
    $fake = Tenancy::fake();

    $tenant = Tenancy::create(['name' => 'Faked'], ['owner' => 7]);

    expect($tenant->status)->toBe(TenantStatus::Active)->and(FlakyStep::$log)->toBe([]);
    $fake->assertProvisioned('faked');
    $fake->assertProvisioned(fn ($t, $options) => $options['owner'] === 7);
});
