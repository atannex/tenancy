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
 * connection, under a name of its own ("tenant_{key}"). A model loaded in
 * tenant A remembers that name, so saving it later inside tenant B still
 * writes to A's database. The shared "tenant" name points at the current
 * tenant for commands, and is forgotten when the tenant ends. Central models
 * stay on the central connection (OnCentralConnection).
 */
final class DatabaseBootstrapper implements Bootstrapper
{
    private ?string $default = null;

    private ?string $connection = null;

    public function __construct(private readonly TenantDatabase $database) {}

    public static function connectionFor(Tenant $tenant): string
    {
        return 'tenant_'.strtolower($tenant->getTenantKey());
    }

    public function bootstrap(Tenant $tenant): void
    {
        if ($tenant->isolationTier() === IsolationTier::Shared) {
            return;
        }

        $this->connection = $this->database->connect($tenant, self::connectionFor($tenant));
        $this->database->connect($tenant);
        $this->default = DB::getDefaultConnection();
        DB::setDefaultConnection($this->connection);
    }

    public function revert(): void
    {
        if ($this->default === null) {
            return;
        }

        $alias = $this->database->connectionName();
        DB::purge($alias);
        DB::purge((string) $this->connection);
        config(["database.connections.{$alias}" => null]);
        DB::setDefaultConnection($this->default);
        $this->default = $this->connection = null;
    }
}
