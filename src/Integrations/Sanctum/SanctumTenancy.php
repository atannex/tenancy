<?php

declare(strict_types=1);

namespace Atannex\Tenancy\Integrations\Sanctum;

use Atannex\Tenancy\Contracts\Tenant;
use Atannex\Tenancy\Exceptions\NotAMember;
use Atannex\Tenancy\Tenancy;
use DateTimeInterface;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Eloquent\Model;
use Laravel\Sanctum\NewAccessToken;
use Laravel\Sanctum\Sanctum;

/**
 * Tenant-scoped Sanctum tokens (opt-in: tenancy.integrations.sanctum.enabled).
 *
 * 1. Publish and run the migration (tenancy-sanctum-migrations), which adds a
 *    nullable tenant_id to personal_access_tokens.
 * 2. Issue tokens with $user->createTenantToken($tenant, 'name').
 * 3. Put `tenant.token` after `tenant` and `auth:sanctum` on tenant API routes:
 *    a token issued for another tenant is refused, and so is a token tied to
 *    no tenant unless tenancy.integrations.sanctum.global_tokens is true.
 */
final class SanctumTenancy
{
    public static function register(Repository $config): void
    {
        if (! class_exists(Sanctum::class) || ! $config->get('tenancy.integrations.sanctum.enabled', false)) {
            return;
        }

        Sanctum::usePersonalAccessTokenModel($config->get('tenancy.integrations.sanctum.model') ?: PersonalAccessToken::class);
    }

    public static function column(): string
    {
        return (string) config('tenancy.integrations.sanctum.column', 'tenant_id');
    }

    /**
     * @param  Model  $user  a model using HasApiTokens and HasTenants
     * @param  list<string>  $abilities
     */
    public static function createToken(Model $user, Tenant|string $tenant, string $name, array $abilities = ['*'], ?DateTimeInterface $expiresAt = null): NewAccessToken
    {
        $tenant = $tenant instanceof Tenant ? $tenant : app(Tenancy::class)->findOrFail($tenant);

        if (! $user->belongsToTenant($tenant)) {
            throw NotAMember::of($tenant->getTenantSlug());
        }

        $plainText = $user->generateTokenString();

        // One insert, so the token is never usable before it is tied to the tenant.
        $token = $user->tokens()->forceCreate([
            'name' => $name,
            'token' => hash('sha256', $plainText),
            'abilities' => $abilities,
            'expires_at' => $expiresAt,
            self::column() => $tenant->getTenantKey(),
        ]);

        return new NewAccessToken($token, $token->getKey().'|'.$plainText);
    }
}
