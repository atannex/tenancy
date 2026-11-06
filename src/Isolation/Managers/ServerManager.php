<?php

declare(strict_types=1);

namespace Atannex\Tenancy\Isolation\Managers;

use Atannex\Tenancy\Contracts\DatabaseManager;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/** Shared plumbing: an admin connection to the server, and safe identifiers. */
abstract class ServerManager implements DatabaseManager
{
    public const string ADMIN_CONNECTION = 'tenancy_admin';

    public function connectionFor(string $name, array $server): array
    {
        return array_merge($server, ['database' => $name]);
    }

    /** @param array<string, mixed> $server */
    protected function admin(array $server): Connection
    {
        config(['database.connections.'.self::ADMIN_CONNECTION => $server]);
        DB::purge(self::ADMIN_CONNECTION);

        return DB::connection(self::ADMIN_CONNECTION);
    }

    protected function identifier(string $name): string
    {
        if (! preg_match('/^[A-Za-z0-9_]{1,63}$/', $name)) {
            throw new InvalidArgumentException("[{$name}] is not a safe database identifier.");
        }

        return $name;
    }

    protected function password(string $password): string
    {
        if (str_contains($password, "'") || str_contains($password, '\\')) {
            throw new InvalidArgumentException('Database passwords may not contain quotes or backslashes.');
        }

        return $password;
    }
}
