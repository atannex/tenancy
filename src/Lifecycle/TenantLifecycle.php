<?php

declare(strict_types=1);

namespace Atannex\Tenancy\Lifecycle;

use Atannex\Tenancy\Contracts\Tenant;
use Atannex\Tenancy\Enums\TenantStatus as S;
use Atannex\Tenancy\Events\TenantArchived;
use Atannex\Tenancy\Events\TenantReactivated;
use Atannex\Tenancy\Events\TenantRestored;
use Atannex\Tenancy\Events\TenantSuspended;
use Atannex\Tenancy\Exceptions\IllegalTransition;
use Illuminate\Database\Eloquent\Model;

/**
 * The guarded tenant state machine:
 * pending → provisioning → active ⇄ suspended → archived → (purged), failed ⇄ provisioning.
 */
final class TenantLifecycle
{
    /** @var array<string, list<S>> */
    private const array ALLOWED = [
        'pending' => [S::Provisioning, S::Active, S::Failed],
        'provisioning' => [S::Active, S::Failed],
        'failed' => [S::Provisioning],
        'active' => [S::Suspended, S::Archived],
        'suspended' => [S::Active, S::Archived],
        'archived' => [S::Active],
    ];

    public function __construct(private readonly Purger $purger) {}

    public function canTransition(Tenant $tenant, S $to): bool
    {
        return in_array($to, self::ALLOWED[$tenant->tenantStatus()->value], true);
    }

    /** @param array<string, mixed> $attributes */
    public function transition(Tenant&Model $tenant, S $to, array $attributes = []): void
    {
        if (! $this->canTransition($tenant, $to)) {
            throw IllegalTransition::between($tenant, $to);
        }

        $from = $tenant->tenantStatus();

        if (! $tenant->exists) {
            $tenant->forceFill(['status' => $to] + $attributes)->save();

            return;
        }

        // Claim the move with a conditional update first: of two workers holding
        // the same stale state (two ProvisionTenant jobs, a retry beside a
        // queued job), only one wins. The save then runs the model events.
        $tenant->getConnection()->transaction(function () use ($tenant, $from, $to, $attributes): void {
            $claimed = $tenant->newQueryWithoutScopes()
                ->whereKey($tenant->getKey())
                ->where('status', $from->value)
                ->toBase()
                ->update(['status' => $to->value]);

            if ($claimed !== 1) {
                $tenant->setAttribute('status', $tenant->newQueryWithoutScopes()->whereKey($tenant->getKey())->value('status') ?? $from->value)->syncOriginalAttribute('status');

                throw IllegalTransition::between($tenant, $to);
            }

            $tenant->forceFill(['status' => $to] + $attributes)->save();
        });
    }

    public function suspend(Tenant&Model $tenant, ?string $reason = null): void
    {
        $data = (array) ($tenant->getAttribute('data') ?? []);
        $data['suspension_reason'] = $reason;

        $this->transition($tenant, S::Suspended, ['suspended_at' => now(), 'data' => $data]);
        event(new TenantSuspended($tenant, $reason));
    }

    public function reactivate(Tenant&Model $tenant): void
    {
        if ($tenant->tenantStatus() !== S::Suspended) {
            throw IllegalTransition::between($tenant, S::Active);
        }

        $data = (array) ($tenant->getAttribute('data') ?? []);
        unset($data['suspension_reason']);

        $this->transition($tenant, S::Active, ['suspended_at' => null, 'data' => $data]);
        event(new TenantReactivated($tenant));
    }

    /** Archive now, purge after the grace period (tenancy.lifecycle.grace_days). */
    public function archive(Tenant&Model $tenant, ?int $graceDays = null): void
    {
        $graceDays ??= (int) config('tenancy.lifecycle.grace_days', 30);

        $this->transition($tenant, S::Archived, ['archived_at' => now(), 'purge_after' => now()->addDays($graceDays)]);
        event(new TenantArchived($tenant));
    }

    public function restore(Tenant&Model $tenant): void
    {
        if ($tenant->tenantStatus() !== S::Archived) {
            throw IllegalTransition::between($tenant, S::Active);
        }

        $this->transition($tenant, S::Active, ['archived_at' => null, 'purge_after' => null, 'suspended_at' => null]);
        event(new TenantRestored($tenant));
    }

    /** Destroy an archived tenant and everything it owns. */
    public function purge(Tenant&Model $tenant): void
    {
        if ($tenant->tenantStatus() !== S::Archived) {
            throw new IllegalTransition("Tenant [{$tenant->getTenantSlug()}] must be archived before it is purged.");
        }

        $this->purger->purge($tenant);
    }
}
