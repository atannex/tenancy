<?php

declare(strict_types=1);

namespace Atannex\Tenancy\Isolation\Managers;

use Atannex\Tenancy\Contracts\DatabaseManager;
use InvalidArgumentException;

/** One SQLite file per tenant under tenancy.database.sqlite_path. */
final class SqliteDatabaseManager implements DatabaseManager
{
    public function create(string $name, array $server): void
    {
        $path = $this->path($name);

        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0755, true);
        }

        touch($path);
    }

    public function delete(string $name, array $server): void
    {
        $path = $this->path($name);

        if (is_file($path)) {
            unlink($path);
        }
    }

    public function exists(string $name, array $server): bool
    {
        return is_file($this->path($name));
    }

    public function connectionFor(string $name, array $server): array
    {
        return array_merge($server, ['driver' => 'sqlite', 'database' => $this->path($name)]);
    }

    private function path(string $name): string
    {
        if (! preg_match('/^[A-Za-z0-9_]{1,63}$/', $name)) {
            throw new InvalidArgumentException("[{$name}] is not a safe database name.");
        }

        return rtrim((string) (config('tenancy.database.sqlite_path') ?: database_path('tenants')), '/').'/'.$name.'.sqlite';
    }
}
