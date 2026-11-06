<?php

declare(strict_types=1);

namespace Atannex\Tenancy\Identification;

use Atannex\Tenancy\Contracts\Tenant;
use Atannex\Tenancy\Contracts\TenantResolver;
use Atannex\Tenancy\Tenancy;
use Illuminate\Http\Request;

/** The tenant a signed-in member chose (switchTenant()); ignored if they are not a member. */
final class SessionResolver implements TenantResolver
{
    public function __construct(private readonly Tenancy $tenancy) {}

    public function resolve(Request $request): ?Tenant
    {
        $user = $request->user();

        if ($user === null || ! $request->hasSession() || ! method_exists($user, 'belongsToTenant')) {
            return null;
        }

        $key = $request->session()->get((string) config('tenancy.people.session_key'));

        if (! is_string($key) || $key === '' || ! $user->belongsToTenant($key)) {
            return null;
        }

        return $this->tenancy->findByKey($key);
    }
}
