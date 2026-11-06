<?php

declare(strict_types=1);

namespace Atannex\Tenancy\Lifecycle\Steps;

use Atannex\Tenancy\Contracts\ProvisioningStep;
use Atannex\Tenancy\Contracts\Tenant;
use Atannex\Tenancy\Enums\IsolationTier;
use Atannex\Tenancy\Isolation\TenantDatabase;
use Atannex\Tenancy\Tenancy;
use Illuminate\Support\Facades\Artisan;
use RuntimeException;

/** Runs tenancy.provisioning.seeder inside the new tenant (skipped when null). */
final class SeedDatabase implements ProvisioningStep
{
    public function __construct(private readonly Tenancy $tenancy, private readonly TenantDatabase $database) {}

    public function handle(Tenant $tenant, array $options): void
    {
        $seeder = $options['seeder'] ?? config('tenancy.provisioning.seeder');

        if (! $seeder) {
            return;
        }

        $connection = $tenant->isolationTier() === IsolationTier::Shared ? $this->tenancy->centralConnection() : $this->database->connectionName();

        $this->tenancy->run($tenant, function () use ($seeder, $connection): void {
            if (Artisan::call('db:seed', ['--class' => $seeder, '--database' => $connection, '--force' => true]) !== 0) {
                throw new RuntimeException('Tenant seeding failed: '.Artisan::output());
            }
        });
    }

    public function rollback(Tenant $tenant, array $options): void
    {
        // Dedicated tiers: the database is dropped. Shared tier: rows are purged with the tenant.
    }
}
