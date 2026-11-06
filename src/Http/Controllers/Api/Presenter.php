<?php

declare(strict_types=1);

namespace Atannex\Tenancy\Http\Controllers\Api;

use Atannex\Tenancy\Contracts\Tenant;
use Atannex\Tenancy\Models\Domain;
use Illuminate\Database\Eloquent\Model;

/** @internal JSON shapes of the management API. */
final class Presenter
{
    /** @return array<string, mixed> */
    public static function tenant(Tenant&Model $tenant): array
    {
        return [
            'id' => $tenant->getTenantKey(),
            'slug' => $tenant->getTenantSlug(),
            'name' => $tenant->getAttribute('name'),
            'status' => $tenant->tenantStatus()->value,
            'isolation' => $tenant->isolationTier()->value,
            'plan' => $tenant->getAttribute('plan'),
            'domains' => method_exists($tenant, 'domains') ? $tenant->domains()->get()->map(self::domain(...))->all() : [],
            'trial_ends_at' => $tenant->getAttribute('trial_ends_at')?->toIso8601String(),
            'suspended_at' => $tenant->getAttribute('suspended_at')?->toIso8601String(),
            'archived_at' => $tenant->getAttribute('archived_at')?->toIso8601String(),
            'purge_after' => $tenant->getAttribute('purge_after')?->toIso8601String(),
            'created_at' => $tenant->getAttribute('created_at')?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    public static function domain(Domain $domain): array
    {
        return [
            'id' => $domain->getKey(),
            'host' => $domain->host,
            'type' => $domain->type->value,
            'verified' => $domain->isVerified(),
            'tls_status' => $domain->tls_status,
        ];
    }
}
