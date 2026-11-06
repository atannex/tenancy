<?php

declare(strict_types=1);

namespace Atannex\Tenancy\Http\Middleware;

use Atannex\Tenancy\Identification\Hosts;
use Atannex\Tenancy\Tenancy;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `central` — the route answers on central hosts only (404 elsewhere). No
 * tenant is current, so in strict mode tenant models still refuse to load.
 *
 * `central:all` also runs the route inside Tenancy::central(), which lifts
 * the tenant scope: tenant models then see every tenant's rows. Use it only
 * for operator tooling that is meant to work across tenants (the management
 * API does), never just to mark routes as central.
 */
final class EnsureCentral
{
    public function __construct(private readonly Tenancy $tenancy) {}

    public function handle(Request $request, Closure $next, ?string $scope = null): Response
    {
        $central = (array) config('tenancy.identification.central_domains', []);

        abort_if($central !== [] && ! Hosts::isCentral($request->getHost(), $central), 404);

        if ($scope === 'all') {
            return $this->tenancy->central(fn () => $next($request));
        }

        return $next($request);
    }
}
