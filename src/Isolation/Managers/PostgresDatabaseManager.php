<?php

declare(strict_types=1);

namespace Atannex\Tenancy\Isolation\Managers;

use Atannex\Tenancy\Contracts\ManagesDatabaseUsers;

/** A PostgreSQL database per tenant. */
final class PostgresDatabaseManager extends ServerManager implements ManagesDatabaseUsers
{
    public function create(string $name, array $server): void
    {
        $this->admin($server)->statement("CREATE DATABASE \"{$this->identifier($name)}\"");
    }

    public function delete(string $name, array $server): void
    {
        $admin = $this->admin($server);
        // PostgreSQL 13+ can end other processes' sessions (queue workers) instead of refusing.
        $force = version_compare($admin->getServerVersion(), '13', '>=') ? ' WITH (FORCE)' : '';

        $admin->statement("DROP DATABASE IF EXISTS \"{$this->identifier($name)}\"{$force}");
    }

    public function exists(string $name, array $server): bool
    {
        return $this->admin($server)->selectOne('SELECT 1 FROM pg_database WHERE datname = ?', [$name]) !== null;
    }

    public function createUser(string $database, string $username, string $password, array $server): void
    {
        $admin = $this->admin($server);
        $user = $this->identifier($username);

        $secret = $this->password($password);

        // A retry after a half-finished create finds the role and sets the new password.
        if ($admin->selectOne('SELECT 1 FROM pg_roles WHERE rolname = ?', [$user]) === null) {
            $admin->statement("CREATE ROLE \"{$user}\" LOGIN");
        }

        $this->secretStatement($admin, "ALTER ROLE \"{$user}\" LOGIN PASSWORD '{$secret}'", $password);
        $admin->statement("GRANT ALL PRIVILEGES ON DATABASE \"{$this->identifier($database)}\" TO \"{$user}\"");
        $admin->statement("ALTER DATABASE \"{$this->identifier($database)}\" OWNER TO \"{$user}\"");
    }

    public function deleteUser(string $username, array $server): void
    {
        $this->admin($server)->statement("DROP ROLE IF EXISTS \"{$this->identifier($username)}\"");
    }
}
