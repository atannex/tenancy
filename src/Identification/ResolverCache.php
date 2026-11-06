<?php

declare(strict_types=1);

namespace Atannex\Tenancy\Identification;

use Atannex\Tenancy\Cache\PrefixedStore;
use Closure;
use Illuminate\Contracts\Cache\Store;

/**
 * Host/slug → tenant key. Only hits are cached, so a new domain works at once;
 * model events forget keys when domains or slugs change.
 */
final class ResolverCache
{
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

        if (is_string($cached)) {
            return $cached;
        }

        $value = $lookup();

        if ($value !== null) {
            $this->store()->put($key, $value, (int) config('tenancy.identification.cache.ttl', 3600));
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
