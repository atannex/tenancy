<?php

declare(strict_types=1);

namespace Atannex\Tenancy\Identification;

use Atannex\Tenancy\Contracts\Tenant;
use Atannex\Tenancy\Contracts\TenantResolver;
use Atannex\Tenancy\Events\ResolvingTenant;
use Atannex\Tenancy\Events\TenantNotResolved;
use Atannex\Tenancy\Events\TenantResolved;
use Illuminate\Http\Request;
use InvalidArgumentException;

final class ResolverPipeline
{
    /** @param list<string> $names resolver names; empty = tenancy.identification.resolvers */
    public function resolve(Request $request, array $names = []): ?Tenant
    {
        $names = $names !== [] ? $names : (array) config('tenancy.identification.resolvers', []);
        $drivers = (array) config('tenancy.identification.drivers', []);

        event(new ResolvingTenant($request));

        foreach ($names as $name) {
            $class = $drivers[$name] ?? throw new InvalidArgumentException("Unknown tenant resolver [{$name}]. Known: ".implode(', ', array_keys($drivers)).'.');
            $resolver = app($class);

            if (! $resolver instanceof TenantResolver) {
                throw new InvalidArgumentException("[{$class}] must implement ".TenantResolver::class.'.');
            }

            if ($tenant = $resolver->resolve($request)) {
                event(new TenantResolved($tenant, $name, $request));

                return $tenant;
            }
        }

        event(new TenantNotResolved($request));

        return null;
    }
}
