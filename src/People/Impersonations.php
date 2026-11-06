<?php

declare(strict_types=1);

namespace Atannex\Tenancy\People;

use Atannex\Tenancy\Contracts\Tenant;
use Atannex\Tenancy\Events\ImpersonationEnded;
use Atannex\Tenancy\Events\ImpersonationIssued;
use Atannex\Tenancy\Events\ImpersonationStarted;
use Atannex\Tenancy\Exceptions\ImpersonationDenied;
use Atannex\Tenancy\Models\Impersonation;
use Atannex\Tenancy\Tenancy;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Support staff entering a tenant as one of its members: a short-lived,
 * single-use token (stored hashed), redeemed inside the tenant, recorded
 * from issue to end.
 */
final class Impersonations
{
    public const string SESSION_KEY = 'tenancy.impersonation';

    public function __construct(private readonly Members $members) {}

    public function issue(Tenant $tenant, Model $impersonator, Model $user, ?int $ttlSeconds = null): string
    {
        $membership = $this->members->find($tenant, $user);

        if (! $membership?->isActive()) {
            throw ImpersonationDenied::notMember();
        }

        if ($membership->role === Members::OWNER && ! config('tenancy.people.impersonation.allow_owners', false)) {
            throw ImpersonationDenied::owner();
        }

        $token = Str::random(64);
        $record = Impersonation::query()->create([
            'tenant_id' => $tenant->getTenantKey(),
            'impersonator_id' => Users::key($impersonator),
            'user_id' => Users::key($user),
            'token_hash' => hash('sha256', $token),
            'expires_at' => now()->addSeconds($ttlSeconds ?? (int) config('tenancy.people.impersonation.ttl', 60)),
        ]);

        event(new ImpersonationIssued($tenant, $record));

        return $token;
    }

    /** Redeem a token inside its tenant: logs the member in on the configured guard. */
    public function enter(string $token): Impersonation
    {
        $record = Impersonation::query()->where('token_hash', hash('sha256', $token))->first() ?? throw ImpersonationDenied::invalid();
        $tenant = app(Tenancy::class)->current();

        match (true) {
            $record->used_at !== null => throw ImpersonationDenied::used(),
            $record->expires_at->isPast() => throw ImpersonationDenied::expired(),
            $tenant === null || $tenant->getTenantKey() !== $record->tenant_id => throw ImpersonationDenied::wrongTenant(),
            default => null,
        };

        $user = Users::find($record->user_id);

        if (! $user instanceof Authenticatable || ! $this->members->find($tenant, $user)?->isActive()) {
            throw ImpersonationDenied::notMember();
        }

        $record->update(['used_at' => now(), 'ip' => app()->bound('request') ? request()->ip() : null]);

        $this->guard()->login($user);
        app('session')->put(self::SESSION_KEY, $record->getKey());

        event(new ImpersonationStarted($tenant, $record));

        return $record;
    }

    public function current(): ?Impersonation
    {
        if (! app()->bound('session') || ! ($id = app('session')->get(self::SESSION_KEY))) {
            return null;
        }

        return Impersonation::query()->whereKey($id)->whereNull('ended_at')->first();
    }

    public function impersonating(): bool
    {
        return $this->current() !== null;
    }

    public function stop(): void
    {
        $record = $this->current();

        if ($record === null) {
            return;
        }

        $record->update(['ended_at' => now()]);
        $this->guard()->logout();
        app('session')->forget(self::SESSION_KEY);

        if ($tenant = app(Tenancy::class)->findByKey($record->tenant_id)) {
            event(new ImpersonationEnded($tenant, $record));
        }
    }

    /** @return StatefulGuard */
    private function guard(): mixed
    {
        return auth()->guard(config('tenancy.people.impersonation.guard'));
    }
}
