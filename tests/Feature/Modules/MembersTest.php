<?php

declare(strict_types=1);

use Atannex\Tenancy\Enums\MembershipStatus;
use Atannex\Tenancy\Events\MemberAdded;
use Atannex\Tenancy\Events\MemberRoleChanged;
use Atannex\Tenancy\Exceptions\LastOwner;
use Atannex\Tenancy\Exceptions\NotAMember;
use Atannex\Tenancy\Exceptions\QuotaExceeded;
use Atannex\Tenancy\Facades\Tenancy;
use Atannex\Tenancy\Identification\ResolverPipeline;
use Atannex\Tenancy\People\Members;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;

require_once __DIR__.'/../../Modules.php';

beforeEach(function () {
    definePlans();
    $this->tenant = planTenant('pro', ['slug' => 'team']);
    $this->members = app(Members::class);
});

it('adds members with a role', function () {
    Event::fake([MemberAdded::class]);
    $user = moduleUser();

    $membership = $this->members->add($this->tenant, $user, 'admin');

    expect($membership->role)->toBe('admin')
        ->and($membership->status)->toBe(MembershipStatus::Active)
        ->and($user->belongsToTenant($this->tenant))->toBeTrue()
        ->and($user->tenantRole($this->tenant))->toBe('admin')
        ->and($user->tenants()->pluck('slug')->all())->toBe(['team']);
    Event::assertDispatched(MemberAdded::class);
});

it('rejects unknown roles', function () {
    $this->members->add($this->tenant, moduleUser(), 'emperor');
})->throws(InvalidArgumentException::class, 'emperor');

it('enforces the seat quota', function () {
    $tenant = planTenant('free');
    $this->members->add($tenant, moduleUser('a'));
    $this->members->add($tenant, moduleUser('b'));

    $this->members->add($tenant, moduleUser('c'));
})->throws(QuotaExceeded::class);

it('never removes or demotes the last owner', function () {
    $owner = moduleUser();
    $this->members->add($this->tenant, $owner, 'owner');

    expect(fn () => $this->members->remove($this->tenant, $owner))->toThrow(LastOwner::class)
        ->and(fn () => $this->members->changeRole($this->tenant, $owner, 'member'))->toThrow(LastOwner::class)
        ->and(fn () => $this->members->suspend($this->tenant, $owner))->toThrow(LastOwner::class);

    $second = moduleUser('b');
    $this->members->add($this->tenant, $second, 'owner');
    $this->members->changeRole($this->tenant, $owner, 'member');
    expect($owner->tenantRole($this->tenant))->toBe('member');
});

it('announces role changes', function () {
    Event::fake([MemberRoleChanged::class]);
    $user = moduleUser();
    $this->members->add($this->tenant, $user, 'member');

    $this->members->changeRole($this->tenant, $user, 'admin');

    Event::assertDispatched(MemberRoleChanged::class, fn ($e) => $e->from === 'member' && $e->to === 'admin');
});

it('suspended members lose access', function () {
    $user = moduleUser();
    $this->members->add($this->tenant, $user, 'admin');
    $this->members->suspend($this->tenant, $user);

    expect($user->belongsToTenant($this->tenant))->toBeFalse();
    $this->members->reactivate($this->tenant, $user);
    expect($user->belongsToTenant($this->tenant))->toBeTrue();
});

it('answers abilities from the role in the current tenant', function () {
    $admin = moduleUser('admin');
    $viewer = moduleUser('viewer');
    $this->members->add($this->tenant, $admin, 'admin');
    $this->members->add($this->tenant, $viewer, 'viewer');

    Tenancy::run($this->tenant, function () use ($admin, $viewer) {
        expect(Gate::forUser($admin)->allows('members.invite'))->toBeTrue()
            ->and(Gate::forUser($viewer)->allows('members.invite'))->toBeFalse();
    });

    expect(Gate::forUser($admin)->allows('members.invite'))->toBeFalse();
});

it('switches the current tenant only for members', function () {
    $user = moduleUser();
    $other = planTenant('pro');
    $this->members->add($this->tenant, $user);

    $this->actingAs($user);
    $user->switchTenant($this->tenant);
    expect($user->currentTenant()?->getTenantKey())->toBe($this->tenant->id);

    $user->switchTenant($other);
})->throws(NotAMember::class);

it('resolves from the session and the user', function () {
    $user = moduleUser();
    $this->members->add($this->tenant, $user);
    $this->actingAs($user);
    $user->switchTenant($this->tenant);

    $request = Request::create('http://central.test/');
    $request->setLaravelSession(app('session.store'));
    $request->setUserResolver(fn () => $user);

    expect(app(ResolverPipeline::class)->resolve($request, ['session'])?->getTenantSlug())->toBe('team')
        ->and(app(ResolverPipeline::class)->resolve($request, ['user'])?->getTenantSlug())->toBe('team');
});

it('ignores a session tenant the user does not belong to', function () {
    $user = moduleUser();
    $this->actingAs($user);
    app('session.store')->put(config('tenancy.people.session_key'), $this->tenant->id);

    $request = Request::create('http://central.test/');
    $request->setLaravelSession(app('session.store'));
    $request->setUserResolver(fn () => $user);

    expect(app(ResolverPipeline::class)->resolve($request, ['session']))->toBeNull();
});
