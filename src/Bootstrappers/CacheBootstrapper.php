<?php

declare(strict_types=1);

namespace Atannex\Tenancy\Bootstrappers;

use Atannex\Tenancy\Cache\PrefixedStore;
use Atannex\Tenancy\Contracts\Bootstrapper;
use Atannex\Tenancy\Contracts\Tenant;
use Atannex\Tenancy\Tenancy;
use Illuminate\Contracts\Cache\Store;
use Illuminate\Support\Facades\DB;

/** Points the configured cache stores at the tenant's own key space (every driver). */
final class CacheBootstrapper implements Bootstrapper
{
    /** @var array<string, Store> */
    private array $originals = [];

    public function bootstrap(Tenant $tenant): void
    {
        $central = app(Tenancy::class)->centralConnection();

        foreach ($this->stores() as $name) {
            // A database store built now must use the central connection, not
            // the dedicated tenant's database the default may point at.
            $repository = DB::usingConnection($central, fn () => app('cache')->store($name));
            $store = $repository->getStore();
            $store = $store instanceof PrefixedStore ? $store->inner() : $store;

            $this->originals[$name] = $store;
            $repository->setStore(new PrefixedStore($store, $tenant->getTenantKey()));
        }
    }

    public function revert(): void
    {
        foreach ($this->originals as $name => $store) {
            app('cache')->store($name)->setStore($store);
        }

        $this->originals = [];
    }

    /** @return list<string> */
    private function stores(): array
    {
        $stores = config('tenancy.cache.stores');

        return array_values(array_unique((array) ($stores ?: [config('cache.default')])));
    }
}
