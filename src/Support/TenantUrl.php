<?php

declare(strict_types=1);

namespace Atannex\Tenancy\Support;

use Atannex\Tenancy\Bootstrappers\UrlBootstrapper;
use Atannex\Tenancy\Contracts\Tenant;
use Atannex\Tenancy\Tenancy;
use Symfony\Component\Routing\Exception\RouteNotFoundException;

/**
 * Links to a given tenant from anywhere (central code, another tenant, a
 * job), without entering that tenant: fills the {tenant} path parameter and
 * roots the link at the tenant's verified domain or subdomain.
 */
final class TenantUrl
{
    public function __construct(private readonly Tenancy $tenancy) {}

    /** @param  array<array-key, mixed>  $parameters */
    public function route(Tenant|string $tenant, string $name, array $parameters = [], bool $absolute = true): string
    {
        $tenant = $this->resolve($tenant);
        $routes = app('router')->getRoutes();

        if ($routes->getByName($name) === null) {
            $routes->refreshNameLookups();
        }

        $route = $routes->getByName($name) ?? throw new RouteNotFoundException("Route [{$name}] not defined.");
        $parameter = (string) config('tenancy.identification.path_parameter', 'tenant');

        if (in_array($parameter, $route->parameterNames(), true) && ! array_key_exists($parameter, $parameters)) {
            $parameters[$parameter] = $tenant->getTenantSlug();
        }

        // The route names its own host ("{tenant}.example.com"): the generator builds it.
        if (! $absolute || $route->getDomain() !== null) {
            return app('url')->route($name, $parameters, $absolute);
        }

        return $this->root($tenant).app('url')->route($name, $parameters, false);
    }

    public function to(Tenant|string $tenant, string $path = '/'): string
    {
        return $this->root($this->resolve($tenant)).'/'.ltrim($path, '/');
    }

    /** scheme://host of the tenant, or the central root when it has no host of its own (path, header). */
    public function root(Tenant $tenant): string
    {
        $host = UrlBootstrapper::hostFor($tenant);

        if ($host !== null) {
            return UrlBootstrapper::scheme().'://'.$host;
        }

        $root = UrlBootstrapper::inRequest() ? request()->root() : (string) config('app.url');

        return rtrim($root, '/');
    }

    private function resolve(Tenant|string $tenant): Tenant
    {
        return $tenant instanceof Tenant ? $tenant : $this->tenancy->findOrFail($tenant);
    }
}
