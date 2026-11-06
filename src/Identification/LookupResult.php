<?php

declare(strict_types=1);

namespace Atannex\Tenancy\Identification;

use Atannex\Tenancy\Contracts\Tenant;
use Atannex\Tenancy\Tenancy;

/** @internal */
final class LookupResult
{
    /** Load the tenant a cached key points at; drop the key if the tenant is gone. */
    public static function tenant(Tenancy $tenancy, ResolverCache $cache, string $key, ?string $id): ?Tenant
    {
        if ($id === null) {
            return null;
        }

        $tenant = $tenancy->findByKey($id);

        if ($tenant === null) {
            $cache->forget($key);
        }

        return $tenant;
    }
}
