<?php

declare(strict_types=1);

namespace Atannex\Tenancy\Plans;

use Atannex\Tenancy\Contracts\Tenant;
use Atannex\Tenancy\Exceptions\FeatureUnavailable;
use Atannex\Tenancy\Models\FeatureOverride;
use Atannex\Tenancy\Tenancy;
use DateTimeInterface;

/** Plan features, with per-tenant overrides that may expire (trials). */
final class Features
{
    public function __construct(private readonly Plans $plans) {}

    /** False when there is no tenant at all. */
    public function active(string $feature, ?Tenant $tenant = null): bool
    {
        $tenant ??= app(Tenancy::class)->current();

        if ($tenant === null) {
            return false;
        }

        $override = FeatureOverride::query()->where('tenant_id', $tenant->getTenantKey())->where('feature', $feature)->first();

        if ($override !== null && $override->isLive()) {
            return $override->enabled;
        }

        return (bool) $this->plans->for($tenant)?->has($feature);
    }

    public function assert(string $feature, ?Tenant $tenant = null): void
    {
        if (! $this->active($feature, $tenant)) {
            throw FeatureUnavailable::for($feature);
        }
    }

    /** @return list<string> */
    public function all(?Tenant $tenant = null): array
    {
        $tenant ??= app(Tenancy::class)->currentOrFail('Tenant features');
        $features = $this->plans->for($tenant)->features ?? [];

        foreach (FeatureOverride::query()->where('tenant_id', $tenant->getTenantKey())->get() as $override) {
            if (! $override->isLive()) {
                continue;
            }

            $features = $override->enabled
                ? array_values(array_unique([...$features, $override->feature]))
                : array_values(array_diff($features, [$override->feature]));
        }

        return $features;
    }

    public function enable(string $feature, ?Tenant $tenant = null, ?DateTimeInterface $until = null): void
    {
        $this->override($feature, true, $tenant, $until);
    }

    public function disable(string $feature, ?Tenant $tenant = null, ?DateTimeInterface $until = null): void
    {
        $this->override($feature, false, $tenant, $until);
    }

    /** Drop the override; the plan decides again. */
    public function forget(string $feature, ?Tenant $tenant = null): void
    {
        $tenant ??= app(Tenancy::class)->currentOrFail('Tenant features');

        FeatureOverride::query()->where('tenant_id', $tenant->getTenantKey())->where('feature', $feature)->delete();
    }

    private function override(string $feature, bool $enabled, ?Tenant $tenant, ?DateTimeInterface $until): void
    {
        $tenant ??= app(Tenancy::class)->currentOrFail('Tenant features');

        FeatureOverride::query()->updateOrCreate(
            ['tenant_id' => $tenant->getTenantKey(), 'feature' => $feature],
            ['enabled' => $enabled, 'expires_at' => $until],
        );
    }
}
