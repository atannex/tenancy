<?php

declare(strict_types=1);

namespace Atannex\Tenancy\Exceptions;

use Atannex\Tenancy\Contracts\Tenant;

final class TenantSuspended extends TenancyException
{
    public static function for(Tenant $tenant): self
    {
        return new self("Tenant [{$tenant->getTenantSlug()}] is suspended.");
    }

    public function publicMessage(): string
    {
        return 'This workspace is suspended.';
    }

    public function getStatusCode(): int
    {
        return 423;
    }
}
