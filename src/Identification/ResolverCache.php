<?php

declare(strict_types=1);

namespace Atannex\Tenancy\Identification;

use Atannex\Tenancy\Cache\PrefixedStore;
use Closure;
use Illuminate\Contracts\Cache\Store;

/**
 * Host/slug → tenant key. Hits are cached for tenancy.identification.cache.ttl
 * seconds; misses for miss_ttl seconds (default 60, 0 = never), so a flood of
 * unknown hosts costs one query per host per minute. Model events forget the
 * keys (both kinds) when tenants, slugs or domains change, so a new domain
 * works at once.
 */
final class ResolverCache
{
    /** Stored for a miss; never a valid tenant key. */
    private const string MISS = "\0tenancy:miss";

    public static function domainKey(string $host): string
    {
        return 'tenancy:domain:'.Hosts::normalize($host);
    }

    public static function slugKey(string $slug): string
    {
        return 'tenancy:slug:'.strtolower($slug);
    }

    /** @param Closure(): ?string $lookup */
    public function remember(string $key, Closure $lookup): ?string
    {
        if (! config('tenancy.identification.cache.enabled', true)) {
            return $lookup();
        }

        $cached = $this->store()->get($key);

        if ($cached === self::MISS) {
            return null;
        }

        if (is_string($cached)) {
            return $cached;
        }

        $value = $lookup();

        if ($value !== null) {
            $this->store()->put($key, $value, (int) config('tenancy.identification.cache.ttl', 3600));
        } elseif (($missTtl = (int) config('tenancy.identification.cache.miss_ttl', 60)) > 0) {
            $this->store()->put($key, self::MISS, $missTtl);
        }

        return $value;
    }

    public function forget(string $key): void
    {
        $this->store()->forget($key);
    }

    /** The central store, never a tenant-prefixed one. */
    private function store(): Store
    {
        $store = app('cache')->store(config('tenancy.identification.cache.store'))->getStore();

        return $store instanceof PrefixedStore ? $store->inner() : $store;
    }
}
