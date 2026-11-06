<?php

declare(strict_types=1);

namespace Atannex\Tenancy\Contracts;

/**
 * Creates and drops one tenant's database (or schema) on a server.
 * $server is the template connection config with the tenant's overrides.
 */
interface DatabaseManager
{
    /** @param array<string, mixed> $server */
    public function create(string $name, array $server): void;

    /** @param array<string, mixed> $server */
    public function delete(string $name, array $server): void;

    /** @param array<string, mixed> $server */
    public function exists(string $name, array $server): bool;

    /**
     * The connection config a tenant uses to reach $name.
     *
     * @param  array<string, mixed>  $server
     * @return array<string, mixed>
     */
    public function connectionFor(string $name, array $server): array;
}
