<?php

declare(strict_types=1);

namespace Atannex\Tenancy\Broadcasting;

use Atannex\Tenancy\Contracts\Tenant;
use Atannex\Tenancy\Tenancy;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Support\Facades\Broadcast;

/** private-tenant.{key}.{name}: a private channel that belongs to one tenant. */
final class TenantChannel extends PrivateChannel
{
    public function __construct(string $name, ?Tenant $tenant = null)
    {
        $tenant ??= app(Tenancy::class)->currentOrFail('A tenant broadcast channel');

        parent::__construct(self::name($tenant, $name));
    }

    public static function name(Tenant $tenant, string $name): string
    {
        return 'tenant.'.$tenant->getTenantKey().'.'.$name;
    }

    /**
     * Authorize "tenant.{tenantKey}.$pattern": refused unless the auth request
     * runs inside that same tenant, then $callback($user, ...$params) decides.
     */
    public static function authorize(string $pattern, callable $callback): void
    {
        Broadcast::channel('tenant.{tenantKey}.'.$pattern, function ($user, string $tenantKey, ...$params) use ($callback) {
            $current = app(Tenancy::class)->current();

            if ($current === null || $current->getTenantKey() !== $tenantKey) {
                return false;
            }

            return $callback($user, ...$params);
        });
    }
}
