<?php

declare(strict_types=1);

namespace Atannex\Tenancy\Exceptions;

use Atannex\Tenancy\Contracts\Tenant;

final class TenantArchived extends TenancyException
{
    public static function for(Tenant $tenant): self
    {
        return new self("Tenant [{$tenant->getTenantSlug()}] is archived.");
    }

    public function getStatusCode(): int
    {
        return 410;
    }
}
