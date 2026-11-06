<?php

declare(strict_types=1);

namespace Atannex\Tenancy\Isolation\Managers;

final class SqlServerDatabaseManager extends ServerManager
{
    public function create(string $name, array $server): void
    {
        $this->admin($server)->statement("CREATE DATABASE [{$this->identifier($name)}]");
    }

    public function delete(string $name, array $server): void
    {
        $this->admin($server)->statement("DROP DATABASE IF EXISTS [{$this->identifier($name)}]");
    }

    public function exists(string $name, array $server): bool
    {
        return $this->admin($server)->selectOne('SELECT name FROM sys.databases WHERE name = ?', [$name]) !== null;
    }
}
