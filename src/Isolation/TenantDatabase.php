<?php

declare(strict_types=1);

namespace Atannex\Tenancy\Isolation;

use Atannex\Tenancy\Contracts\DatabaseManager;
use Atannex\Tenancy\Contracts\ManagesDatabaseUsers;
use Atannex\Tenancy\Contracts\Tenant;
use Atannex\Tenancy\Enums\IsolationTier;
use Atannex\Tenancy\Isolation\Managers\PostgresSchemaManager;
use Atannex\Tenancy\Tenancy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Where a dedicated-tier tenant's data lives, and the lifecycle of that
 * database or schema. Per-tenant overrides (host, port, username, password,
 * database) come from the tenant's encrypted `connection` attribute, so a
 * tenant can sit on its own server.
 */
final class TenantDatabase
{
    private const array SERVER_KEYS = ['host', 'port', 'username', 'password', 'unix_socket', 'sslmode'];

    public function name(Tenant $tenant): string
    {
        $overrides = $this->overrides($tenant);

        return (string) ($overrides['database'] ?? config('tenancy.database.prefix', 'tenant_').strtolower($tenant->getTenantKey()));
    }

    /** @return array<string, mixed> */
    public function template(): array
    {
        $name = config('tenancy.database.template_connection') ?: app(Tenancy::class)->centralConnection();
        $config = config("database.connections.{$name}");

        if (! is_array($config)) {
            throw new InvalidArgumentException("Tenant template connection [{$name}] is not configured.");
        }

        return $config;
    }

    /** @return array<string, mixed> */
    public function server(Tenant $tenant): array
    {
        return array_merge($this->template(), array_intersect_key($this->overrides($tenant), array_flip(self::SERVER_KEYS)));
    }

    public function manager(Tenant $tenant, ?IsolationTier $tier = null): DatabaseManager
    {
        $tier ??= $tenant->isolationTier();
        $driver = (string) ($this->server($tenant)['driver'] ?? '');

        if ($tier === IsolationTier::Shared) {
            throw new InvalidArgumentException("Tenant [{$tenant->getTenantSlug()}] is on the shared tier and has no database of its own.");
        }

        if ($tier === IsolationTier::Schema) {
            if ($driver !== 'pgsql') {
                throw new InvalidArgumentException("The schema tier needs PostgreSQL; the template connection uses [{$driver}].");
            }

            return app(PostgresSchemaManager::class);
        }

        $class = config("tenancy.database.managers.{$driver}") ?? throw new InvalidArgumentException("No tenant database manager for driver [{$driver}].");

        return app($class);
    }

    /** @return array<string, mixed> */
    public function config(Tenant $tenant, ?IsolationTier $tier = null): array
    {
        return $this->manager($tenant, $tier)->connectionFor($this->name($tenant), $this->server($tenant));
    }

    public function exists(Tenant $tenant, ?IsolationTier $tier = null): bool
    {
        return $this->manager($tenant, $tier)->exists($this->name($tenant), $this->server($tenant));
    }

    /** Create the tenant's database (idempotent) and, if enabled, its own login. */
    public function create(Tenant $tenant, ?IsolationTier $tier = null): void
    {
        $manager = $this->manager($tenant, $tier);
        $name = $this->name($tenant);
        $overrides = ['database' => $name];

        if (! $manager->exists($name, $this->server($tenant))) {
            $manager->create($name, $this->server($tenant));
        }

        if (config('tenancy.database.dedicated_users') && $manager instanceof ManagesDatabaseUsers && empty($this->overrides($tenant)['username'])) {
            $overrides['username'] = Str::limit($name, 32, '');
            $overrides['password'] = Str::password(32, symbols: false);
            $manager->createUser($name, $overrides['username'], $overrides['password'], $this->server($tenant));
        }

        $this->remember($tenant, $overrides);
    }

    public function delete(Tenant $tenant, ?IsolationTier $tier = null): void
    {
        $manager = $this->manager($tenant, $tier);
        $overrides = $this->overrides($tenant);
        $server = array_merge($this->template(), array_intersect_key($overrides, array_flip(['host', 'port', 'unix_socket', 'sslmode'])));

        DB::purge($this->connectionName());
        $manager->delete($this->name($tenant), $server);

        if (config('tenancy.database.dedicated_users') && $manager instanceof ManagesDatabaseUsers && ! empty($overrides['username'])) {
            $manager->deleteUser((string) $overrides['username'], $server);
        }
    }

    /** Register (and reset) a named connection pointing at the tenant's database. */
    public function connect(Tenant $tenant, ?string $connection = null, ?IsolationTier $tier = null): string
    {
        $connection ??= $this->connectionName();

        config(["database.connections.{$connection}" => $this->config($tenant, $tier)]);
        DB::purge($connection);

        return $connection;
    }

    public function connectionName(): string
    {
        return (string) config('tenancy.database.tenant_connection', 'tenant');
    }

    /** @return array<string, mixed> */
    private function overrides(Tenant $tenant): array
    {
        return $tenant instanceof Model ? (array) ($tenant->getAttribute('connection') ?? []) : [];
    }

    /** @param array<string, mixed> $overrides */
    private function remember(Tenant $tenant, array $overrides): void
    {
        if ($tenant instanceof Model) {
            $tenant->forceFill(['connection' => array_merge($this->overrides($tenant), $overrides)])->saveQuietly();
        }
    }
}
