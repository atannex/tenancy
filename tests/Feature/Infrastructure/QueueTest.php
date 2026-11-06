<?php

declare(strict_types=1);

use Atannex\Tenancy\Enums\TenantStatus;
use Atannex\Tenancy\Facades\Tenancy;
use Atannex\Tenancy\Models\Tenant;
use Atannex\Tenancy\Tests\Fixtures\RecordCentralJob;
use Atannex\Tenancy\Tests\Fixtures\RecordTenantJob;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    RecordTenantJob::$seen = [];
    config(['queue.default' => 'database', 'tenancy.bootstrappers' => []]);
    $this->a = Tenant::factory()->create(['slug' => 'aa']);
    $this->b = Tenant::factory()->create(['slug' => 'bb']);
});

function work(): void
{
    test()->artisan('queue:work', ['connection' => 'database', '--stop-when-empty' => true, '--tries' => 1])->assertSuccessful();
}

it('runs jobs inside the tenant that dispatched them', function () {
    Tenancy::run($this->a, fn () => RecordTenantJob::dispatch());
    Tenancy::run($this->b, fn () => RecordTenantJob::dispatch());
    RecordTenantJob::dispatch();

    work();

    expect(RecordTenantJob::$seen)->toBe(['aa', 'bb', null])
        ->and(Tenancy::current())->toBeNull();
});

it('keeps central jobs central', function () {
    Tenancy::run($this->a, fn () => RecordCentralJob::dispatch());
    work();

    expect(RecordTenantJob::$seen)->toBe([null]);
});

it('fails jobs of suspended tenants', function () {
    Tenancy::run($this->a, fn () => RecordTenantJob::dispatch());
    $this->a->update(['status' => TenantStatus::Suspended]);

    work();

    expect(RecordTenantJob::$seen)->toBe([])->and(DB::table('failed_jobs')->count())->toBe(1);
});

it('leaves sync jobs in the caller context', function () {
    config(['queue.default' => 'sync']);

    Tenancy::run($this->a, function () {
        RecordTenantJob::dispatch();
        expect(tenant()->getTenantSlug())->toBe('aa');
    });

    expect(RecordTenantJob::$seen)->toBe(['aa']);
});

it('runs commands for each tenant, inline or queued', function () {
    $this->artisan('tenants:run', ['commandname' => 'about --only=environment'])->assertSuccessful();

    $this->artisan('tenants:run', ['commandname' => 'about --only=environment', '--queue' => true, '--tenants' => ['aa']])->assertSuccessful();
    expect(DB::table('jobs')->count())->toBe(1)
        ->and(json_decode(DB::table('jobs')->value('payload'), true)['tenant_id'])->toBe($this->a->id);
});

it('schedules a command for each tenant', function () {
    $event = app(Schedule::class)->forEachTenant('reports:send')->daily();

    expect($event->command)->toContain('tenants:run')->toContain('reports:send')->toContain('--queue');
});
