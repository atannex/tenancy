<?php

declare(strict_types=1);

use Atannex\Tenancy\Contracts\Tenant;
use Atannex\Tenancy\Support\TenantUrl;
use Atannex\Tenancy\Tenancy;

if (! function_exists('tenant')) {
    /** The tenant in context, or null in central code. */
    function tenant(): ?Tenant
    {
        return app(Tenancy::class)->current();
    }
}

if (! function_exists('tenant_route')) {
    /**
     * A named route for a tenant, from anywhere: fills {tenant} and roots the
     * link at the tenant's domain or subdomain.
     *
     * @param  array<array-key, mixed>  $parameters
     */
    function tenant_route(Tenant|string $tenant, string $name, array $parameters = [], bool $absolute = true): string
    {
        return app(TenantUrl::class)->route($tenant, $name, $parameters, $absolute);
    }
}

if (! function_exists('tenant_url')) {
    /** An absolute URL to a path on a tenant's domain or subdomain. */
    function tenant_url(Tenant|string $tenant, string $path = '/'): string
    {
        return app(TenantUrl::class)->to($tenant, $path);
    }
}
