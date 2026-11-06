<?php

declare(strict_types=1);

namespace Atannex\Tenancy\Http\Middleware;

use Atannex\Tenancy\Enums\MembershipStatus;
use Atannex\Tenancy\Exceptions\NotAMember;
use Atannex\Tenancy\People\Members;
use Atannex\Tenancy\Tenancy;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** `tenant.member` / `tenant.member:admin,owner` — an active member of the current tenant (with one of the roles). */
final class EnsureMember
{
    public function __construct(private readonly Tenancy $tenancy, private readonly Members $members) {}

    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $tenant = $this->tenancy->currentOrFail('tenant.member');
        $user = $request->user() ?? throw new AuthenticationException;
        $membership = $this->members->find($tenant, $user);

        if ($membership === null || $membership->status !== MembershipStatus::Active || ($roles !== [] && ! in_array($membership->role, $roles, true))) {
            throw NotAMember::of($tenant->getTenantSlug());
        }

        return $next($request);
    }
}
