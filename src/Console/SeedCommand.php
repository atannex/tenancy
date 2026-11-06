<?php

declare(strict_types=1);

namespace Atannex\Tenancy\Console;

use Atannex\Tenancy\Console\Concerns\SelectsTenants;
use Atannex\Tenancy\Enums\IsolationTier;
use Atannex\Tenancy\Isolation\TenantDatabase;
use Atannex\Tenancy\Tenancy;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

/** Seeds inside every selected tenant: shared-tier rows are stamped with the tenant by BelongsToTenant. */
final class SeedCommand extends Command
{
    use SelectsTenants;

    protected $description = 'Seed tenant data inside each tenant';

    public function __construct()
    {
        $this->signature = 'tenants:seed {--class= : The seeder class (default: tenancy.migrations.seeder or Database\\Seeders\\TenantSeeder)} '.$this->tenantOptions();

        parent::__construct();
    }

    public function handle(Tenancy $tenancy, TenantDatabase $database): int
    {
        $class = $this->option('class') ?: config('tenancy.migrations.seeder', 'Database\\Seeders\\TenantSeeder');
        $failures = 0;

        $this->eachTenant(function ($tenant) use ($class, $tenancy, $database, &$failures): void {
            $connection = $tenant->isolationTier() === IsolationTier::Shared ? $tenancy->centralConnection() : $database->connectionName();
            $code = Artisan::call('db:seed', ['--class' => $class, '--database' => $connection, '--force' => true]);
            $this->components->twoColumnDetail($tenant->getTenantSlug(), $code === 0 ? '<fg=green>seeded</>' : '<fg=red>failed</>');
            $failures += $code === 0 ? 0 : 1;
        });

        return $failures === 0 ? self::SUCCESS : self::FAILURE;
    }
}
