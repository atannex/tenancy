<?php

declare(strict_types=1);

namespace Atannex\Tenancy\Contracts;

/** Managers that can give each tenant its own database login. */
interface ManagesDatabaseUsers
{
    /** @param array<string, mixed> $server */
    public function createUser(string $database, string $username, string $password, array $server): void;

    /** @param array<string, mixed> $server */
    public function deleteUser(string $username, array $server): void;
}
