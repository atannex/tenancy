<?php

declare(strict_types=1);

use Atannex\Tenancy\Exceptions\ImpersonationDenied;
use Atannex\Tenancy\Facades\Tenancy;
use Atannex\Tenancy\People\Impersonations;
use Atannex\Tenancy\People\Members;

require_once __DIR__.'/../../Modules.php';

beforeEach(function () {
    definePlans();
    $this->tenant = planTenant('pro');
    $this->support = moduleUser('Support');
    $this->member = moduleUser('Member');
    $this->owner = moduleUser('Owner');
    app(Members::class)->add($this->tenant, $this->owner, 'owner');
    app(Members::class)->add($this->tenant, $this->member, 'member');
    $this->impersonations = app(Impersonations::class);
});

it('enters as the member with a single-use token and stops', function () {
    $token = $this->impersonations->issue($this->tenant, $this->support, $this->member);

    Tenancy::run($this->tenant, function () use ($token) {
        $record = $this->impersonations->enter($token);

        expect(auth()->id())->toBe($this->member->id)
            ->and($this->impersonations->impersonating())->toBeTrue()
            ->and($this->impersonations->current()?->is($record))->toBeTrue();

        expect(fn () => $this->impersonations->enter($token))->toThrow(ImpersonationDenied::class, 'used');

        $this->impersonations->stop();
        expect(auth()->check())->toBeFalse()
            ->and($this->impersonations->impersonating())->toBeFalse()
            ->and($record->fresh()->ended_at)->not->toBeNull();
    });
});

it('expires tokens', function () {
    $token = $this->impersonations->issue($this->tenant, $this->support, $this->member);
    $this->travel(2)->minutes();

    Tenancy::run($this->tenant, fn () => $this->impersonations->enter($token));
})->throws(ImpersonationDenied::class, 'expired');

it('refuses owners unless allowed', function () {
    expect(fn () => $this->impersonations->issue($this->tenant, $this->support, $this->owner))->toThrow(ImpersonationDenied::class, 'owner');

    config(['tenancy.people.impersonation.allow_owners' => true]);
    expect($this->impersonations->issue($this->tenant, $this->support, $this->owner))->toHaveLength(64);
});

it('refuses non-members and the wrong tenant', function () {
    expect(fn () => $this->impersonations->issue($this->tenant, $this->support, moduleUser('x')))->toThrow(ImpersonationDenied::class);

    $token = $this->impersonations->issue($this->tenant, $this->support, $this->member);
    expect(fn () => Tenancy::run(planTenant('pro'), fn () => $this->impersonations->enter($token)))->toThrow(ImpersonationDenied::class, 'tenant');
});
