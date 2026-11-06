<?php

declare(strict_types=1);

namespace Atannex\Tenancy\Exceptions;

use Atannex\Tenancy\Contracts\Tenant;

/** Pending, provisioning or failed: not ready to serve. */
final class TenantInactive extends TenancyException
{
    public static function for(Tenant $tenant): self
    {
        return new self("Tenant [{$tenant->getTenantSlug()}] is {$tenant->tenantStatus()->value}.");
    }

    public function getStatusCode(): int
    {
        return 503;
    }
}
