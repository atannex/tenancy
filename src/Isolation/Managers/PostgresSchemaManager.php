<?php

declare(strict_types=1);

namespace Atannex\Tenancy\Isolation\Managers;

use Atannex\Tenancy\Contracts\ManagesDatabaseUsers;

/** The schema tier: one PostgreSQL schema per tenant, reached through search_path. */
final class PostgresSchemaManager extends ServerManager implements ManagesDatabaseUsers
{
    public function create(string $name, array $server): void
    {
        $this->admin($server)->statement("CREATE SCHEMA IF NOT EXISTS \"{$this->identifier($name)}\"");
    }

    public function delete(string $name, array $server): void
    {
        $this->admin($server)->statement("DROP SCHEMA IF EXISTS \"{$this->identifier($name)}\" CASCADE");
    }

    public function exists(string $name, array $server): bool
    {
        return $this->admin($server)->selectOne('SELECT 1 FROM information_schema.schemata WHERE schema_name = ?', [$name]) !== null;
    }

    public function connectionFor(string $name, array $server): array
    {
        return array_merge($server, ['search_path' => $name, 'schema' => $name]);
    }

    public function createUser(string $database, string $username, string $password, array $server): void
    {
        $admin = $this->admin($server);
        $user = $this->identifier($username);
        $schema = $this->identifier($database);

        $secret = $this->password($password);

        // A retry after a half-finished create finds the role and sets the new password.
        if ($admin->selectOne('SELECT 1 FROM pg_roles WHERE rolname = ?', [$user]) === null) {
            $admin->statement("CREATE ROLE \"{$user}\" LOGIN");
        }

        $this->secretStatement($admin, "ALTER ROLE \"{$user}\" LOGIN PASSWORD '{$secret}'", $password);
        $admin->statement("GRANT USAGE, CREATE ON SCHEMA \"{$schema}\" TO \"{$user}\"");
        $admin->statement("ALTER SCHEMA \"{$schema}\" OWNER TO \"{$user}\"");
    }

    public function deleteUser(string $username, array $server): void
    {
        $this->admin($server)->statement("DROP ROLE IF EXISTS \"{$this->identifier($username)}\"");
    }
}
