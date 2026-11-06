<?php

declare(strict_types=1);

namespace Atannex\Tenancy\Lifecycle\Steps;

use Atannex\Tenancy\Contracts\ProvisioningStep;
use Atannex\Tenancy\Contracts\Tenant;
use Atannex\Tenancy\Enums\IsolationTier;
use Atannex\Tenancy\Isolation\TenantDatabase;
use Illuminate\Database\Eloquent\Model;

/** Dedicated tiers: create the tenant's database or schema. */
final class CreateDatabase implements ProvisioningStep
{
    private const string FLAG = 'tenancy_created_database';

    public function __construct(private readonly TenantDatabase $database) {}

    public function handle(Tenant $tenant, array $options): void
    {
        if ($tenant->isolationTier() !== IsolationTier::Shared && $this->database->create($tenant) && $tenant instanceof Model) {
            $tenant->forceFill(['data' => [self::FLAG => true] + (array) ($tenant->getAttribute('data') ?? [])])->saveQuietly();
        }
    }

    /** Drops the database only if this step created it. */
    public function rollback(Tenant $tenant, array $options): void
    {
        if (! $tenant instanceof Model) {
            return;
        }

        $data = (array) ($tenant->getAttribute('data') ?? []);

        if ($tenant->isolationTier() !== IsolationTier::Shared && ($data[self::FLAG] ?? false)) {
            $this->database->delete($tenant);
            unset($data[self::FLAG]);
            $tenant->forceFill(['data' => $data])->saveQuietly();
        }
    }
}
