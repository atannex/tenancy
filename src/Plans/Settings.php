<?php

declare(strict_types=1);

namespace Atannex\Tenancy\Plans;

use Atannex\Tenancy\Contracts\Tenant;
use Atannex\Tenancy\Tenancy;

final class Settings
{
    public function for(?Tenant $tenant = null): TenantSettings
    {
        return new TenantSettings($tenant ?? app(Tenancy::class)->currentOrFail('Tenant settings'));
    }
}
