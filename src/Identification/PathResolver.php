<?php

declare(strict_types=1);

namespace Atannex\Tenancy\Identification;

use Atannex\Tenancy\Contracts\Tenant;
use Atannex\Tenancy\Contracts\TenantResolver;
use Atannex\Tenancy\Tenancy;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;

/**
 * "/{tenant}/..." routes. The parameter is removed from the route so
 * controllers never see it, and set as a URL default so route() keeps it.
 */
final class PathResolver implements TenantResolver
{
    public function __construct(private readonly Tenancy $tenancy) {}

    public function resolve(Request $request): ?Tenant
    {
        $route = $request->route();
        $parameter = (string) config('tenancy.identification.path_parameter', 'tenant');

        if (! is_object($route) || ! $route->hasParameter($parameter)) {
            return null;
        }

        $value = (string) $route->parameter($parameter);
        $tenant = $this->tenancy->find($value);

        if ($tenant !== null) {
            $route->forgetParameter($parameter);
            URL::defaults([$parameter => $tenant->getTenantSlug()]);
        }

        return $tenant;
    }
}
