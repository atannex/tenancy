<?php

declare(strict_types=1);

namespace Atannex\Tenancy\Http;

use Atannex\Tenancy\Contracts\Tenant;
use Atannex\Tenancy\Enums\TenantStatus;
use Atannex\Tenancy\Exceptions\TenantArchived;
use Atannex\Tenancy\Exceptions\TenantInactive;
use Atannex\Tenancy\Exceptions\TenantSuspended;

final class AccessGuard
{
    public static function assertAccessible(Tenant $tenant): void
    {
        match ($tenant->tenantStatus()) {
            TenantStatus::Active => null,
            TenantStatus::Suspended => throw TenantSuspended::for($tenant),
            TenantStatus::Archived => throw TenantArchived::for($tenant),
            default => throw TenantInactive::for($tenant),
        };
    }
}
