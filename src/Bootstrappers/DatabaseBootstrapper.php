<?php

declare(strict_types=1);

namespace Atannex\Tenancy\Bootstrappers;

use Atannex\Tenancy\Contracts\Bootstrapper;
use Atannex\Tenancy\Contracts\Tenant;
use Atannex\Tenancy\Enums\IsolationTier;
use Atannex\Tenancy\Isolation\TenantDatabase;
use Illuminate\Support\Facades\DB;

/**
 * Schema and database tiers: make the tenant's database the default
 * connection. Central models stay on the central connection
 * (OnCentralConnection). The shared tier needs nothing here.
 */
final class DatabaseBootstrapper implements Bootstrapper
{
    private ?string $default = null;

    public function __construct(private readonly TenantDatabase $database) {}

    public function bootstrap(Tenant $tenant): void
    {
        if ($tenant->isolationTier() === IsolationTier::Shared) {
            return;
        }

        $connection = $this->database->connect($tenant);
        $this->default = DB::getDefaultConnection();
        DB::setDefaultConnection($connection);
    }

    public function revert(): void
    {
        if ($this->default === null) {
            return;
        }

        DB::purge($this->database->connectionName());
        DB::setDefaultConnection($this->default);
        $this->default = null;
    }
}
