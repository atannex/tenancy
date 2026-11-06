<?php

declare(strict_types=1);

namespace Atannex\Tenancy\Lifecycle\Steps;

use Atannex\Tenancy\Contracts\ProvisioningStep;
use Atannex\Tenancy\Contracts\Tenant;
use Atannex\Tenancy\Enums\IsolationTier;
use Atannex\Tenancy\Isolation\TenantDatabase;

/** Dedicated tiers: create the tenant's database or schema. */
final class CreateDatabase implements ProvisioningStep
{
    public function __construct(private readonly TenantDatabase $database) {}

    public function handle(Tenant $tenant, array $options): void
    {
        if ($tenant->isolationTier() !== IsolationTier::Shared) {
            $this->database->create($tenant);
        }
    }

    public function rollback(Tenant $tenant, array $options): void
    {
        if ($tenant->isolationTier() !== IsolationTier::Shared) {
            $this->database->delete($tenant);
        }
    }
}
