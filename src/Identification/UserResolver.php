<?php

declare(strict_types=1);

namespace Atannex\Tenancy\Identification;

use Atannex\Tenancy\Contracts\Tenant;
use Atannex\Tenancy\Contracts\TenantResolver;
use Illuminate\Http\Request;

/** The signed-in user's current tenant (HasTenants::currentTenant()). */
final class UserResolver implements TenantResolver
{
    public function resolve(Request $request): ?Tenant
    {
        $user = $request->user();

        return $user !== null && method_exists($user, 'currentTenant') ? $user->currentTenant() : null;
    }
}
