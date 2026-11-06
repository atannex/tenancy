<?php

declare(strict_types=1);

namespace Atannex\Tenancy\Contracts;

use Atannex\Tenancy\Enums\IsolationTier;
use Atannex\Tenancy\Enums\TenantStatus;
use Atannex\Tenancy\Models\Domain;
use Illuminate\Database\Eloquent\Relations\HasMany;

interface Tenant
{
    public function getTenantKey(): string;

    public function getTenantSlug(): string;

    public function tenantStatus(): TenantStatus;

    public function isolationTier(): IsolationTier;

    /**
     * The tenant's custom domains (tenancy.models.domain rows).
     *
     * @return HasMany<Domain, covariant \Illuminate\Database\Eloquent\Model>
     */
    public function domains(): HasMany;
}
