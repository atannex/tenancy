<?php

declare(strict_types=1);

namespace Atannex\Tenancy\Http\Middleware;

use Atannex\Tenancy\Domains\AliasRedirect;
use Atannex\Tenancy\Exceptions\TenantNotFound;
use Atannex\Tenancy\Http\AccessGuard;
use Atannex\Tenancy\Identification\ResolverPipeline;
use Atannex\Tenancy\Lifecycle\Maintenance;
use Atannex\Tenancy\Tenancy;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;

/** `tenant` / `tenant:domain,header` — the request must belong to a tenant. */
class InitializeTenancy
{
    protected bool $required = true;

    /** Runs after the session and authentication (tenant.session). */
    protected bool $late = false;

    public function __construct(
        protected readonly Tenancy $tenancy,
        protected readonly ResolverPipeline $pipeline,
    ) {}

    public function handle(Request $request, Closure $next, string ...$resolvers): Response
    {
        if (! $this->late && array_intersect($resolvers, ['session', 'user']) !== []) {
            throw new \InvalidArgumentException('The session and user resolvers need the session and the signed-in user: use the tenant.session middleware.');
        }

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

        if ($host = $request->attributes->get('tenancy.host')) {
            if (config('tenancy.domains.redirect_aliases', true) && $target = AliasRedirect::target($host)) {
                return new RedirectResponse(str_replace('://'.$request->getHttpHost(), '://'.$target, $request->fullUrl()), 301);
            }
        }

        if ($bypass = app(Maintenance::class)->check($request, $tenant)) {
            return $bypass;
        }

        $this->tenancy->initialize($tenant);

        return $next($request);
    }
}
