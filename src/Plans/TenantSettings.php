<?php

declare(strict_types=1);

namespace Atannex\Tenancy\Plans;

use Atannex\Tenancy\Contracts\Tenant;
use Atannex\Tenancy\Models\Setting;
use Atannex\Tenancy\Support\CentralCache;

/** One tenant's settings over tenancy.settings.defaults; values keep their JSON types. */
final class TenantSettings
{
    public function __construct(public readonly Tenant $tenant) {}

    public function get(string $key, mixed $default = null): mixed
    {
        $all = $this->all();

        return array_key_exists($key, $all) ? $all[$key] : $default;
    }

    public function set(string $key, mixed $value): void
    {
        Setting::query()->updateOrCreate(['tenant_id' => $this->tenant->getTenantKey(), 'key' => $key], ['value' => $value]);
        $this->flush();
    }

    public function forget(string $key): void
    {
        Setting::query()->where('tenant_id', $this->tenant->getTenantKey())->where('key', $key)->delete();
        $this->flush();
    }

    /** @return array<string, mixed> */
    public function all(): array
    {
        return array_replace((array) config('tenancy.settings.defaults', []), $this->stored());
    }

    /** @return array<string, mixed> */
    private function stored(): array
    {
        $store = CentralCache::store();
        $cached = $store->get($this->cacheKey());

        if (is_array($cached)) {
            return $cached;
        }

        $stored = Setting::query()->where('tenant_id', $this->tenant->getTenantKey())->pluck('value', 'key')->all();
        $store->put($this->cacheKey(), $stored, (int) config('tenancy.settings.cache_ttl', 3600));

        return $stored;
    }

    private function flush(): void
    {
        CentralCache::store()->forget($this->cacheKey());
    }

    private function cacheKey(): string
    {
        return 'tenancy:settings:'.$this->tenant->getTenantKey();
    }
}
