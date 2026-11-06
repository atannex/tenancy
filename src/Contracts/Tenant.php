<?php

declare(strict_types=1);

namespace Atannex\Tenancy\Contracts;

use Atannex\Tenancy\Enums\IsolationTier;
use Atannex\Tenancy\Enums\TenantStatus;

interface Tenant
{
    public function getTenantKey(): string;

    public function getTenantSlug(): string;

    public function tenantStatus(): TenantStatus;

    public function isolationTier(): IsolationTier;
}
