<?php

declare(strict_types=1);

namespace Atannex\Tenancy\Identification;

use Atannex\Tenancy\Contracts\Tenant;
use Atannex\Tenancy\Contracts\TenantResolver;
use Atannex\Tenancy\Tenancy;
use Illuminate\Http\Request;

final class DomainResolver implements TenantResolver
{
    public function __construct(private readonly Tenancy $tenancy, private readonly ResolverCache $cache) {}

    public function resolve(Request $request): ?Tenant
    {
        $host = Hosts::normalize($request->getHost());

        if ($host === '' || Hosts::isCentral($host, (array) config('tenancy.identification.central_domains', []))) {
            return null;
        }

        $key = ResolverCache::domainKey($host);
        $id = $this->cache->remember($key, function () use ($host): ?string {
            $class = config('tenancy.models.domain');
            // Unverified domains never resolve: anyone can type a host into a form.
            $value = $class::query()->where('host', $host)->whereNotNull('verified_at')->value('tenant_id');

            return $value === null ? null : (string) $value;
        });

        $tenant = LookupResult::tenant($this->tenancy, $this->cache, $key, $id);

        if ($tenant !== null) {
            $request->attributes->set('tenancy.host', $host);
        }

        return $tenant;
    }
}
