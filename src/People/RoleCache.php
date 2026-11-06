<?php

declare(strict_types=1);

namespace Atannex\Tenancy\People;

use Atannex\Tenancy\Contracts\Tenant;

/**
 * Remembers each user's role in the current tenant for the Gate, so a page
 * full of @can checks costs one membership query instead of one per check.
 * Flushed whenever tenancy starts or ends and whenever a membership changes.
 */
final class RoleCache
{
    /** @var array<string, string|null> */
    private array $roles = [];

    public function role(object $user, Tenant $tenant): ?string
    {
        $key = $tenant->getTenantKey().'|'.$user::class.'|'.(method_exists($user, 'getKey') ? (string) $user->getKey() : spl_object_id($user));

        if (! array_key_exists($key, $this->roles)) {
            $this->roles[$key] = $user->tenantRole($tenant);
        }

        return $this->roles[$key];
    }

    public function flush(): void
    {
        $this->roles = [];
    }
}
