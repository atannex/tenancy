<?php

declare(strict_types=1);

namespace Atannex\Tenancy\People;

use Atannex\Tenancy\Contracts\Tenant;
use Atannex\Tenancy\Database\OnCentralConnection;
use Atannex\Tenancy\Enums\MembershipStatus;
use Atannex\Tenancy\Exceptions\NotAMember;
use Atannex\Tenancy\Models\Membership;
use Atannex\Tenancy\Tenancy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * For the app's user model. Users stay central; one user can belong to many
 * tenants through tenant_memberships.
 *
 * Queries compare user keys as strings, so they work whatever the user key
 * type is (int, uuid, ulid) on every database.
 *
 * @mixin Model
 */
trait HasTenants
{
    // Users are central: on dedicated tiers the default connection points at the tenant.
    use OnCentralConnection;

    /** @return Builder<Membership> */
    public function memberships(): Builder
    {
        return Membership::query()->where('user_id', (string) $this->getKey());
    }

    /** Tenants this user is an active member of. */
    public function tenants(): Builder
    {
        $model = config('tenancy.models.tenant');
        $tenant = new $model;

        return $model::query()->whereIn(
            $tenant->getKeyName(),
            $this->memberships()->where('status', MembershipStatus::Active->value)->select('tenant_id'),
        );
    }

    public function membershipFor(Tenant|string $tenant): ?Membership
    {
        $key = $tenant instanceof Tenant ? $tenant->getTenantKey() : $tenant;

        return $this->memberships()->where('tenant_id', $key)->first();
    }

    public function belongsToTenant(Tenant|string $tenant): bool
    {
        return (bool) $this->membershipFor($tenant)?->isActive();
    }

    /** The active role in a tenant (default: the current one). */
    public function tenantRole(Tenant|string|null $tenant = null): ?string
    {
        $tenant ??= app(Tenancy::class)->current();

        if ($tenant === null) {
            return null;
        }

        $membership = $this->membershipFor($tenant);

        return $membership?->isActive() ? $membership->role : null;
    }

    /**
     * The tenant this user works in: the one chosen with switchTenant(), a
     * current_tenant_id column, or their only tenant.
     */
    public function currentTenant(): ?Tenant
    {
        $tenancy = app(Tenancy::class);
        $candidates = [];

        if (app()->bound('session') && ($key = app('session')->get((string) config('tenancy.people.session_key')))) {
            $candidates[] = (string) $key;
        }

        if (($column = $this->getAttribute('current_tenant_id')) !== null) {
            $candidates[] = (string) $column;
        }

        foreach ($candidates as $key) {
            if ($this->belongsToTenant($key) && ($tenant = $tenancy->findByKey($key))) {
                return $tenant;
            }
        }

        $tenants = $this->tenants()->limit(2)->get();

        return $tenants->count() === 1 ? $tenants->first() : null;
    }

    public function switchTenant(Tenant|string $tenant): Tenant
    {
        $tenant = $tenant instanceof Tenant ? $tenant : app(Tenancy::class)->findOrFail($tenant);

        if (! $this->belongsToTenant($tenant)) {
            throw NotAMember::of($tenant->getTenantSlug());
        }

        if (app()->bound('session')) {
            app('session')->put((string) config('tenancy.people.session_key'), $tenant->getTenantKey());
        }

        if (array_key_exists('current_tenant_id', $this->getAttributes())) {
            $this->forceFill(['current_tenant_id' => $tenant->getTenantKey()])->save();
        }

        return $tenant;
    }
}
