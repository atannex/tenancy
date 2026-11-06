<?php

declare(strict_types=1);

use Atannex\Tenancy\Audit\AuditRecorder;
use Atannex\Tenancy\Models\AuditEvent;
use Atannex\Tenancy\People\Members;
use Atannex\Tenancy\Plans\Plans;

require_once __DIR__.'/../../Modules.php';

beforeEach(fn () => definePlans());

it('records membership and plan events with the actor', function () {
    $tenant = planTenant('free');
    $actor = moduleUser('Admin');
    $this->actingAs($actor);

    app(Members::class)->add($tenant, moduleUser('New'), 'member');
    app(Plans::class)->change($tenant, 'pro');

    $events = AuditEvent::where('tenant_id', $tenant->id)->orderBy('created_at')->get();

    expect($events->pluck('action')->all())->toBe(['member.added', 'tenant.plan.changed'])
        ->and($events[0]->actor_id)->toBe((string) $actor->id)
        ->and($events[0]->subject_type)->not->toBeNull()
        ->and($events[1]->metadata)->toMatchArray(['from' => 'free', 'to' => 'pro']);
});

it('records custom actions', function () {
    $tenant = planTenant();

    $event = app(AuditRecorder::class)->record('report.exported', null, ['rows' => 3], $tenant);

    expect($event->action)->toBe('report.exported')->and($event->metadata)->toBe(['rows' => 3]);
});

it('can be switched off', function () {
    config(['tenancy.audit_log.enabled' => false]);

    expect(app(AuditRecorder::class)->record('x', null, [], planTenant()))->toBeNull()
        ->and(AuditEvent::count())->toBe(0);
});

it('prunes old events', function () {
    $tenant = planTenant();
    app(AuditRecorder::class)->record('old', null, [], $tenant);
    $this->travel(400)->days();
    app(AuditRecorder::class)->record('new', null, [], $tenant);

    $this->artisan('tenancy:prune-audit')->assertSuccessful();

    expect(AuditEvent::pluck('action')->all())->toBe(['new']);
});
