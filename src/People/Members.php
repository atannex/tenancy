<?php

declare(strict_types=1);

namespace Atannex\Tenancy\People;

use Atannex\Tenancy\Contracts\Tenant;
use Atannex\Tenancy\Enums\MembershipStatus;
use Atannex\Tenancy\Events\MemberAdded;
use Atannex\Tenancy\Events\MemberReactivated;
use Atannex\Tenancy\Events\MemberRemoved;
use Atannex\Tenancy\Events\MemberRoleChanged;
use Atannex\Tenancy\Events\MemberSuspended;
use Atannex\Tenancy\Exceptions\LastOwner;
use Atannex\Tenancy\Exceptions\NotAMember;
use Atannex\Tenancy\Models\Membership;
use Atannex\Tenancy\Plans\Quotas;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

final class Members
{
    public const string OWNER = 'owner';

    public function __construct(private readonly Roles $roles, private readonly Quotas $quotas) {}

    /** Add (or reactivate) a member. Takes a seat when the tenant has a seat quota. */
    public function add(Tenant $tenant, Model $user, ?string $role = null, ?Model $invitedBy = null): Membership
    {
        $role ??= (string) config('tenancy.people.default_role', 'member');
        $this->roles->assertExists($role);

        $membership = $this->find($tenant, $user);

        if ($membership?->isActive()) {
            if ($membership->role !== $role) {
                $this->changeRole($tenant, $user, $role);
            }

            return $membership->refresh();
        }

        $this->takeSeat($tenant);

        $membership ??= new Membership(['tenant_id' => $tenant->getTenantKey(), 'user_id' => Users::key($user)]);
        $membership->fill([
            'role' => $role,
            'status' => MembershipStatus::Active,
            'invited_by' => $invitedBy ? Users::key($invitedBy) : $membership->invited_by,
            'joined_at' => $membership->joined_at ?? now(),
        ])->save();

        event(new MemberAdded($tenant, $membership));

        return $membership;
    }

    public function remove(Tenant $tenant, Model $user): void
    {
        $membership = $this->findOrFail($tenant, $user);
        $this->guardLastOwner($membership);

        $membership->delete();
        event(new MemberRemoved($tenant, $membership));
    }

    public function changeRole(Tenant $tenant, Model $user, string $role): Membership
    {
        $this->roles->assertExists($role);
        $membership = $this->findOrFail($tenant, $user);
        $from = $membership->role;

        if ($from === $role) {
            return $membership;
        }

        if ($from === self::OWNER) {
            $this->guardLastOwner($membership);
        }

        $membership->update(['role' => $role]);
        event(new MemberRoleChanged($tenant, $membership, $from, $role));

        return $membership;
    }

    public function suspend(Tenant $tenant, Model $user): Membership
    {
        $membership = $this->findOrFail($tenant, $user);
        $this->guardLastOwner($membership);

        $membership->update(['status' => MembershipStatus::Suspended]);
        event(new MemberSuspended($tenant, $membership));

        return $membership;
    }

    public function reactivate(Tenant $tenant, Model $user): Membership
    {
        $membership = $this->findOrFail($tenant, $user);

        if (! $membership->isActive()) {
            $this->takeSeat($tenant);
            $membership->update(['status' => MembershipStatus::Active]);
            event(new MemberReactivated($tenant, $membership));
        }

        return $membership;
    }

    public function find(Tenant $tenant, Model $user): ?Membership
    {
        return Membership::query()->where('tenant_id', $tenant->getTenantKey())->where('user_id', Users::key($user))->first();
    }

    /** @return Collection<int, Membership> */
    public function of(Tenant $tenant, bool $activeOnly = true): Collection
    {
        return Membership::query()
            ->where('tenant_id', $tenant->getTenantKey())
            ->when($activeOnly, fn ($q) => $q->where('status', MembershipStatus::Active->value))
            ->orderBy('joined_at')
            ->get();
    }

    /** @return Collection<int, Membership> */
    public function owners(Tenant $tenant): Collection
    {
        return $this->of($tenant)->where('role', self::OWNER)->values();
    }

    public function activeCount(Tenant $tenant): int
    {
        return Membership::query()->where('tenant_id', $tenant->getTenantKey())->where('status', MembershipStatus::Active->value)->count();
    }

    public function assertSeatAvailable(Tenant $tenant): void
    {
        $this->takeSeat($tenant);
    }

    private function takeSeat(Tenant $tenant): void
    {
        if ($key = config('tenancy.people.seat_quota')) {
            $this->quotas->for((string) $key, $tenant)->assert(1);
        }
    }

    private function findOrFail(Tenant $tenant, Model $user): Membership
    {
        return $this->find($tenant, $user) ?? throw NotAMember::of($tenant->getTenantSlug());
    }

    private function guardLastOwner(Membership $membership): void
    {
        if ($membership->role !== self::OWNER || ! $membership->isActive()) {
            return;
        }

        $others = Membership::query()
            ->where('tenant_id', $membership->tenant_id)
            ->where('role', self::OWNER)
            ->where('status', MembershipStatus::Active->value)
            ->whereKeyNot($membership->getKey())
            ->exists();

        if (! $others) {
            throw LastOwner::make();
        }
    }
}
