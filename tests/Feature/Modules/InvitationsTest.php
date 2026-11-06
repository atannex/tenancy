<?php

declare(strict_types=1);

use Atannex\Tenancy\Exceptions\InvitationInvalid;
use Atannex\Tenancy\Exceptions\QuotaExceeded;
use Atannex\Tenancy\Notifications\TenantInvitation;
use Atannex\Tenancy\People\Invitations;
use Atannex\Tenancy\People\Members;
use Atannex\Tenancy\People\Routes;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;

require_once __DIR__.'/../../Modules.php';

beforeEach(function () {
    definePlans();
    Notification::fake();
    $this->tenant = planTenant('pro');
    $this->invitations = app(Invitations::class);
});

it('invites by email and notifies', function () {
    $pending = $this->invitations->invite($this->tenant, 'Grace@Example.test', 'admin');

    expect($pending->invitation->email)->toBe('grace@example.test')
        ->and($pending->token)->toHaveLength(64)
        ->and($pending->invitation->token_hash)->not->toBe($pending->token);

    Notification::assertSentTo(new AnonymousNotifiable, TenantInvitation::class, function ($n, $channels, $notifiable) {
        return $notifiable->routes['mail'] === 'grace@example.test';
    });
});

it('accepts an invitation once', function () {
    $pending = $this->invitations->invite($this->tenant, 'grace@example.test', 'admin');
    $user = moduleUser('Grace', 'grace@example.test');

    $membership = $this->invitations->accept($pending->token, $user);

    expect($membership->role)->toBe('admin')->and($user->belongsToTenant($this->tenant))->toBeTrue();
    expect(fn () => $this->invitations->accept($pending->token, $user))->toThrow(InvitationInvalid::class, 'accepted');
});

it('refuses expired, revoked, unknown and mismatched invitations', function () {
    $user = moduleUser('Grace', 'grace@example.test');

    $expired = $this->invitations->invite($this->tenant, 'grace@example.test');
    $this->travel(8)->days();
    expect(fn () => $this->invitations->accept($expired->token, $user))->toThrow(InvitationInvalid::class, 'expired');

    $revoked = $this->invitations->invite($this->tenant, 'grace@example.test');
    $this->invitations->revoke($revoked->invitation);
    expect(fn () => $this->invitations->accept($revoked->token, $user))->toThrow(InvitationInvalid::class, 'revoked')
        ->and(fn () => $this->invitations->accept(str_repeat('x', 64), $user))->toThrow(InvitationInvalid::class, 'not found');

    $other = $this->invitations->invite($this->tenant, 'someone@example.test');
    expect(fn () => $this->invitations->accept($other->token, $user))->toThrow(InvitationInvalid::class, 'email');
});

it('resends with a new token', function () {
    $first = $this->invitations->invite($this->tenant, 'grace@example.test');
    $second = $this->invitations->resend($first->invitation);
    $user = moduleUser('Grace', 'grace@example.test');

    expect(fn () => $this->invitations->accept($first->token, $user))->toThrow(InvitationInvalid::class);
    expect($this->invitations->accept($second->token, $user)->role)->toBe('member');
});

it('checks seats on invite and on accept', function () {
    $tenant = planTenant('free');
    app(Members::class)->add($tenant, moduleUser('a'));
    $pending = $this->invitations->invite($tenant, 'b@example.test');
    app(Members::class)->add($tenant, moduleUser('c'));

    expect(fn () => $this->invitations->accept($pending->token, moduleUser('b', 'b@example.test')))->toThrow(QuotaExceeded::class)
        ->and(fn () => $this->invitations->invite($tenant, 'd@example.test'))->toThrow(QuotaExceeded::class);
});

it('accepts through the optional route', function () {
    config(['tenancy.people.routes.enabled' => true]);
    app(Routes::class)->register();
    Route::getRoutes()->refreshNameLookups();

    $pending = $this->invitations->invite($this->tenant, 'grace@example.test');
    $user = moduleUser('Grace', 'grace@example.test');

    $this->actingAs($user)->get(route('tenancy.invitations.accept', $pending->token))->assertRedirect('/');
    expect($user->belongsToTenant($this->tenant))->toBeTrue();
});
