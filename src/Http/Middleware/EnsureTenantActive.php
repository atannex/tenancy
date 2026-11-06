<?php

declare(strict_types=1);

namespace Atannex\Tenancy\Http\Middleware;

use Atannex\Tenancy\Http\AccessGuard;
use Atannex\Tenancy\Tenancy;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** `tenant.active` — for tenants initialized some other way (e.g. a user's current tenant). */
final class EnsureTenantActive
{
    public function __construct(private readonly Tenancy $tenancy) {}

    public function handle(Request $request, Closure $next): Response
    {
        AccessGuard::assertAccessible($this->tenancy->currentOrFail());

        return $next($request);
    }
}
