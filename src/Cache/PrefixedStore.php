<?php

declare(strict_types=1);

namespace Atannex\Tenancy\Cache;

use BadMethodCallException;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Store;

/**
 * Wraps any cache store so every key lives under "tenant:{key}:{version}:".
 * flush() bumps the version instead of wiping the shared store, so a
 * tenant can clear its cache on every driver without touching anyone
 * else's keys; orphaned keys expire with their TTL.
 */
final class PrefixedStore implements LockProvider, Store
{
    private ?int $version = null;

    public function __construct(private readonly Store $inner, private readonly string $tenantKey) {}

    public function inner(): Store
    {
        return $this->inner;
    }

    public function tenantKey(): string
    {
        return $this->tenantKey;
    }

    public function get($key)
    {
        return $this->inner->get($this->key($key));
    }

    public function many(array $keys)
    {
        $values = $this->inner->many(array_map($this->key(...), $keys));
        $result = [];

        foreach ($keys as $key) {
            $result[$key] = $values[$this->key($key)] ?? null;
        }

        return $result;
    }

    public function put($key, $value, $seconds)
    {
        return $this->inner->put($this->key($key), $value, $seconds);
    }

    public function putMany(array $values, $seconds)
    {
        $prefixed = [];

        foreach ($values as $key => $value) {
            $prefixed[$this->key($key)] = $value;
        }

        return $this->inner->putMany($prefixed, $seconds);
    }

    public function increment($key, $value = 1)
    {
        return $this->inner->increment($this->key($key), $value);
    }

    public function decrement($key, $value = 1)
    {
        return $this->inner->decrement($this->key($key), $value);
    }

    public function forever($key, $value)
    {
        return $this->inner->forever($this->key($key), $value);
    }

    public function touch($key, $seconds)
    {
        return method_exists($this->inner, 'touch') ? $this->inner->touch($this->key($key), $seconds) : false;
    }

    public function forget($key)
    {
        return $this->inner->forget($this->key($key));
    }

    /** Clears this tenant's cache only. */
    public function flush()
    {
        $this->version = $this->version() + 1;
        $this->inner->forever($this->versionKey(), $this->version);

        return true;
    }

    public function getPrefix()
    {
        return $this->inner->getPrefix().$this->prefix();
    }

    public function lock($name, $seconds = 0, $owner = null)
    {
        return $this->lockProvider()->lock($this->tenantNamespace().$name, $seconds, $owner);
    }

    public function restoreLock($name, $owner)
    {
        return $this->lockProvider()->restoreLock($this->tenantNamespace().$name, $owner);
    }

    /** Tags are namespaced per tenant (taggable drivers only). */
    public function tags($names)
    {
        if (! method_exists($this->inner, 'tags')) {
            throw new BadMethodCallException('The underlying cache store does not support tagging.');
        }

        $names = is_array($names) ? $names : func_get_args();

        return $this->inner->tags(array_map(fn ($name) => $this->tenantNamespace().$name, $names));
    }

    private function lockProvider(): LockProvider
    {
        if (! $this->inner instanceof LockProvider) {
            throw new BadMethodCallException('The underlying cache store does not support locks.');
        }

        return $this->inner;
    }

    private function key(string $key): string
    {
        return $this->prefix().$key;
    }

    private function prefix(): string
    {
        return $this->tenantNamespace().$this->version().':';
    }

    private function tenantNamespace(): string
    {
        return 'tenant:'.$this->tenantKey.':';
    }

    private function versionKey(): string
    {
        return $this->tenantNamespace().'__version';
    }

    private function version(): int
    {
        return $this->version ??= (int) ($this->inner->get($this->versionKey()) ?? 0);
    }
}
