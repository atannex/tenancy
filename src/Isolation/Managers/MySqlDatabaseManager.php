<?php

declare(strict_types=1);

namespace Atannex\Tenancy\Isolation\Managers;

use Atannex\Tenancy\Contracts\ManagesDatabaseUsers;

/** MySQL and MariaDB. */
final class MySqlDatabaseManager extends ServerManager implements ManagesDatabaseUsers
{
    public function create(string $name, array $server): void
    {
        $charset = $this->identifier((string) ($server['charset'] ?? 'utf8mb4'));
        $collation = $this->identifier((string) ($server['collation'] ?? 'utf8mb4_unicode_ci'));

        $this->admin($server)->statement("CREATE DATABASE `{$this->identifier($name)}` CHARACTER SET `{$charset}` COLLATE `{$collation}`");
    }

    public function delete(string $name, array $server): void
    {
        $this->admin($server)->statement("DROP DATABASE IF EXISTS `{$this->identifier($name)}`");
    }

    public function exists(string $name, array $server): bool
    {
        return $this->admin($server)->selectOne('SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?', [$name]) !== null;
    }

    public function createUser(string $database, string $username, string $password, array $server): void
    {
        $admin = $this->admin($server);
        $user = $this->identifier($username);

        foreach ($this->hosts() as $host) {
            $admin->statement("CREATE USER IF NOT EXISTS '{$user}'@'{$host}' IDENTIFIED BY '{$this->password($password)}'");
            $admin->statement("GRANT ALL PRIVILEGES ON `{$this->identifier($database)}`.* TO '{$user}'@'{$host}'");
        }
    }

    public function deleteUser(string $username, array $server): void
    {
        foreach ($this->hosts() as $host) {
            $this->admin($server)->statement("DROP USER IF EXISTS '{$this->identifier($username)}'@'{$host}'");
        }
    }

    /**
     * Account hosts. "localhost" is included by default because MySQL/MariaDB
     * match an anonymous ''@'localhost' account before 'user'@'%'.
     *
     * @return list<string>
     */
    private function hosts(): array
    {
        return array_map(function (string $host): string {
            if (! preg_match('/^[A-Za-z0-9.%_:-]+$/', $host)) {
                throw new \InvalidArgumentException("[{$host}] is not a valid account host.");
            }

            return $host;
        }, (array) config('tenancy.database.user_hosts', ['%', 'localhost']));
    }
}
