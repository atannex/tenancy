<?php

declare(strict_types=1);

namespace Atannex\Tenancy\Exceptions;

use Atannex\Tenancy\Contracts\Tenant;
use Atannex\Tenancy\Enums\TenantStatus;

final class IllegalTransition extends TenancyException
{
    public static function between(Tenant $tenant, TenantStatus $to): self
    {
        return new self("Tenant [{$tenant->getTenantSlug()}] cannot go from {$tenant->tenantStatus()->value} to {$to->value}.");
    }

    public function getStatusCode(): int
    {
        return 409;
    }
}
