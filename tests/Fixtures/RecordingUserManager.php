<?php

declare(strict_types=1);

namespace Atannex\Tenancy\Tests\Fixtures;

use Atannex\Tenancy\Contracts\DatabaseManager;
use Atannex\Tenancy\Contracts\ManagesDatabaseUsers;
use Atannex\Tenancy\Isolation\Managers\SqliteDatabaseManager;

/** SQLite files plus recorded (fake) database logins. */
final class RecordingUserManager implements DatabaseManager, ManagesDatabaseUsers
{
    /** @var list<string> */
    public static array $calls = [];

    public function __construct(private readonly SqliteDatabaseManager $files) {}

    public function create(string $name, array $server): void
    {
        $this->files->create($name, $server);
    }

    public function delete(string $name, array $server): void
    {
        $this->files->delete($name, $server);
    }

    public function exists(string $name, array $server): bool
    {
        return $this->files->exists($name, $server);
    }

    public function connectionFor(string $name, array $server): array
    {
        return $this->files->connectionFor($name, $server);
    }

    public function createUser(string $database, string $username, string $password, array $server): void
    {
        if (in_array('create:'.$username, self::$calls, true)) {
            throw new \RuntimeException("User {$username} exists");
        }

        self::$calls[] = 'create:'.$username;
    }

    public function deleteUser(string $username, array $server): void
    {
        self::$calls[] = 'delete:'.$username;
    }
}
