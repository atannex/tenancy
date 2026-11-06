<?php

declare(strict_types=1);

namespace Atannex\Tenancy\People;

use Illuminate\Support\Str;

/** Tenant roles and their abilities, from tenancy.people.roles. */
final class Roles
{
    public function exists(string $role): bool
    {
        return array_key_exists($role, (array) config('tenancy.people.roles', []));
    }

    /** @return list<string> */
    public function abilities(string $role): array
    {
        return array_values((array) config("tenancy.people.roles.{$role}", []));
    }

    public function allows(string $role, string $ability): bool
    {
        foreach ($this->abilities($role) as $pattern) {
            if (Str::is($pattern, $ability)) {
                return true;
            }
        }

        return false;
    }

    public function assertExists(string $role): void
    {
        if (! $this->exists($role)) {
            throw new \InvalidArgumentException("Unknown tenant role [{$role}].");
        }
    }
}
