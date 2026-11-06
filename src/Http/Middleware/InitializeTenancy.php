<?php

declare(strict_types=1);

namespace Atannex\Tenancy\Http\Middleware;

use Atannex\Tenancy\Exceptions\TenantNotFound;
use Atannex\Tenancy\Http\AccessGuard;
use Atannex\Tenancy\Identification\ResolverPipeline;
use Atannex\Tenancy\Tenancy;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** `tenant` / `tenant:domain,header` — the request must belong to a tenant. */
class InitializeTenancy
{
    protected bool $required = true;

    public function __construct(
        protected readonly Tenancy $tenancy,
        protected readonly ResolverPipeline $pipeline,
    ) {}

    public function handle(Request $request, Closure $next, string ...$resolvers): Response
    {
        if ($this->tenancy->initialized()) {
            return $next($request);
        }

        $tenant = $this->pipeline->resolve($request, array_values($resolvers));

        if ($tenant === null) {
            if ($this->required) {
                throw TenantNotFound::forHost($request->getHost());
            }

            return $next($request);
        }

        if (config('tenancy.identification.require_active', true)) {
            AccessGuard::assertAccessible($tenant);
        }

        $this->tenancy->initialize($tenant);

        return $next($request);
    }
}
