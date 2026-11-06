<?php

declare(strict_types=1);

namespace Atannex\Tenancy\People;

use Atannex\Tenancy\Contracts\Tenant;
use Atannex\Tenancy\Events\InvitationAccepted;
use Atannex\Tenancy\Events\InvitationRevoked;
use Atannex\Tenancy\Events\InvitationSent;
use Atannex\Tenancy\Exceptions\InvitationInvalid;
use Atannex\Tenancy\Exceptions\NotAMember;
use Atannex\Tenancy\Models\Invitation;
use Atannex\Tenancy\Models\Membership;
use Atannex\Tenancy\Tenancy;
use Illuminate\Contracts\Notifications\Dispatcher;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

/** Invitations by email. Only a SHA-256 hash of each token is stored. */
final class Invitations
{
    public function __construct(private readonly Members $members, private readonly Roles $roles) {}

    public function invite(Tenant $tenant, string $email, ?string $role = null, ?Model $invitedBy = null): PendingInvitation
    {
        $role ??= (string) config('tenancy.people.default_role', 'member');
        $this->roles->assertExists($role);

        if ($role === 'owner' && $invitedBy !== null && $this->members->find($tenant, $invitedBy)?->role !== 'owner') {
            throw NotAMember::of($tenant->getTenantSlug());
        }

        $this->members->assertSeatAvailable($tenant);

        $token = Str::random(64);
        $invitation = Invitation::query()->create([
            'tenant_id' => $tenant->getTenantKey(),
            'email' => self::normalise($email),
            'role' => $role,
            'token_hash' => self::hash($token),
            'invited_by' => $invitedBy ? Users::key($invitedBy) : null,
            'expires_at' => now()->addDays((int) config('tenancy.people.invitations.expires_in_days', 7)),
        ]);

        $pending = new PendingInvitation($invitation, $token);
        $this->notify($pending);
        event(new InvitationSent($tenant, $invitation));

        return $pending;
    }

    public function find(string $token): ?Invitation
    {
        return Invitation::query()->where('token_hash', self::hash($token))->first();
    }

    public function accept(string $token, Model $user): Membership
    {
        $invitation = $this->find($token) ?? throw InvitationInvalid::notFound();

        match (true) {
            $invitation->accepted_at !== null => throw InvitationInvalid::accepted(),
            $invitation->revoked_at !== null => throw InvitationInvalid::revoked(),
            $invitation->expires_at->isPast() => throw InvitationInvalid::expired(),
            default => null,
        };

        if (config('tenancy.people.invitations.match_email', true)
            && self::normalise((string) $user->getAttribute('email')) !== $invitation->email) {
            throw InvitationInvalid::emailMismatch();
        }

        $tenant = app(Tenancy::class)->findByKey($invitation->tenant_id) ?? throw InvitationInvalid::notFound();

        return DB::connection(app(Tenancy::class)->centralConnection())->transaction(function () use ($invitation, $user, $tenant) {
            // An existing member keeps their role; an invitation never changes it.
            $existing = $this->members->find($tenant, $user);
            $membership = $existing?->isActive() ? $existing : $this->members->add($tenant, $user, $invitation->role, Users::find($invitation->invited_by));
            $invitation->update(['accepted_at' => now(), 'accepted_by' => Users::key($user)]);
            event(new InvitationAccepted($tenant, $invitation, $membership));

            return $membership;
        });
    }

    /** Issue a new token (the old one stops working) and send again. */
    public function resend(Invitation $invitation): PendingInvitation
    {
        if ($invitation->accepted_at !== null) {
            throw InvitationInvalid::accepted();
        }

        $token = Str::random(64);
        $invitation->update([
            'token_hash' => self::hash($token),
            'revoked_at' => null,
            'expires_at' => now()->addDays((int) config('tenancy.people.invitations.expires_in_days', 7)),
        ]);

        $pending = new PendingInvitation($invitation, $token);
        $this->notify($pending);

        return $pending;
    }

    public function revoke(Invitation $invitation): void
    {
        $invitation->update(['revoked_at' => now()]);

        if ($tenant = app(Tenancy::class)->findByKey($invitation->tenant_id)) {
            event(new InvitationRevoked($tenant, $invitation));
        }
    }

    private function notify(PendingInvitation $pending): void
    {
        $class = config('tenancy.people.invitations.notification');

        if (! $class || ! class_exists(Notification::class) || ! app()->bound(Dispatcher::class)) {
            return;
        }

        Notification::route('mail', $pending->invitation->email)
            ->notify(new $class($pending->invitation, $pending->token));
    }

    private static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    private static function normalise(string $email): string
    {
        return Str::lower(trim($email));
    }
}
