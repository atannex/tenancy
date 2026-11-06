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

/** Dedicated tiers: run the tenant migrations (shared-tier tables migrate with the app). */
final class MigrateDatabase implements ProvisioningStep
{
    public function __construct(private readonly Tenancy $tenancy, private readonly TenantDatabase $database) {}

    public function handle(Tenant $tenant, array $options): void
    {
        if ($tenant->isolationTier() === IsolationTier::Shared) {
            return;
        }

        $this->tenancy->run($tenant, function (): void {
            $code = Artisan::call('migrate', [
                '--database' => $this->database->connectionName(),
                '--path' => array_values((array) config('tenancy.migrations.paths', [])),
                '--realpath' => true,
                '--force' => true,
            ]);

            if ($code !== 0) {
                throw new RuntimeException('Tenant migrations failed: '.Artisan::output());
            }
        });
    }

    public function rollback(Tenant $tenant, array $options): void
    {
        // CreateDatabase's rollback drops the database.
    }
}
