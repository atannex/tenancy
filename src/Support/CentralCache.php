<?php

declare(strict_types=1);

namespace Atannex\Tenancy\Support;

use Illuminate\Contracts\Cache\Store;

/**
 * The cache store underneath any tenant prefixing, for data the package
 * keys by tenant itself (settings, resolver lookups).
 *
 * @internal
 */
final class CentralCache
{
    public static function store(): Store
    {
        $store = app('cache')->store(config('tenancy.identification.cache.store'))->getStore();

        while (method_exists($store, 'inner')) {
            $store = $store->inner();
        }

        return $store;
    }
}
