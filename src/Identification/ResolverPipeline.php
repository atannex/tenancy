<?php

declare(strict_types=1);

namespace Atannex\Tenancy\Identification;

use Atannex\Tenancy\Contracts\Tenant;
use Atannex\Tenancy\Contracts\TenantResolver;
use Atannex\Tenancy\Events\ResolvingTenant;
use Atannex\Tenancy\Events\TenantNotResolved;
use Atannex\Tenancy\Events\TenantResolved;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Http\Request;
use InvalidArgumentException;

final class ResolverPipeline
{
    public function __construct(
        private readonly Container $app,
        private readonly Repository $config,
        private readonly Dispatcher $events,
    ) {}

    /** @param list<string> $names resolver names; empty = tenancy.identification.resolvers */
    public function resolve(Request $request, array $names = []): ?Tenant
    {
        $names = $names !== [] ? $names : (array) $this->config->get('tenancy.identification.resolvers', []);
        $drivers = (array) $this->config->get('tenancy.identification.drivers', []);

        $this->events->dispatch(new ResolvingTenant($request));

        foreach ($names as $name) {
            $class = $drivers[$name] ?? throw new InvalidArgumentException("Unknown tenant resolver [{$name}]. Known: ".implode(', ', array_keys($drivers)).'.');
            $resolver = $this->app->make($class);

            if (! $resolver instanceof TenantResolver) {
                throw new InvalidArgumentException("[{$class}] must implement ".TenantResolver::class.'.');
            }

            if ($tenant = $resolver->resolve($request)) {
                $this->events->dispatch(new TenantResolved($tenant, $name, $request));

                return $tenant;
            }
        }

        $this->events->dispatch(new TenantNotResolved($request));

        return null;
    }
}
