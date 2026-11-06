<?php

declare(strict_types=1);

namespace Atannex\Tenancy\Identification;

use Atannex\Tenancy\Contracts\Tenant;
use Atannex\Tenancy\Contracts\TenantResolver;
use Atannex\Tenancy\Tenancy;
use Illuminate\Http\Request;

/** "{slug}.central-domain" — exactly one label in front of a central domain. */
final class SubdomainResolver implements TenantResolver
{
    public function __construct(private readonly Tenancy $tenancy, private readonly ResolverCache $cache) {}

    public function resolve(Request $request): ?Tenant
    {
        $host = Hosts::normalize($request->getHost());

        // Longest first, so x.app.example.com matches app.example.com before example.com.
        $centrals = (array) config('tenancy.identification.central_domains', []);
        usort($centrals, fn ($a, $b) => strlen($b) <=> strlen($a));

        foreach ($centrals as $central) {
            $suffix = '.'.Hosts::normalize($central);

            if (! str_ends_with($host, $suffix)) {
                continue;
            }

            $slug = substr($host, 0, -strlen($suffix));

            if ($slug === '' || str_contains($slug, '.')) {
                continue;
            }

            $key = ResolverCache::slugKey($slug);
            $id = $this->cache->remember($key, function () use ($slug): ?string {
                $query = $this->tenancy->query();
                $value = $query->where('slug', $slug)->value($query->getModel()->getKeyName());

                return $value === null ? null : (string) $value;
            });

            return LookupResult::tenant($this->tenancy, $this->cache, $key, $id);
        }

        return null;
    }
}
