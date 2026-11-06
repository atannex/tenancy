<?php

declare(strict_types=1);

namespace Atannex\Tenancy\Http\Middleware;

use Atannex\Tenancy\Integrations\Sanctum\SanctumTenancy;
use Atannex\Tenancy\Tenancy;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * `tenant.token` — the request's personal access token must belong to the
 * tenant in context (run it after `tenant` and `auth:sanctum`). Cookie-based
 * SPA requests carry no token and pass; so do tokens tied to no tenant on
 * central routes. A token tied to no tenant is refused inside a tenant unless
 * tenancy.integrations.sanctum.global_tokens is true.
 */
final class EnsureTokenTenant
{
    public function __construct(private readonly Tenancy $tenancy) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $token = is_object($user) && method_exists($user, 'currentAccessToken') ? $user->currentAccessToken() : null;

        // TransientToken (first-party cookie auth) is not a model: nothing to compare.
        if ($token instanceof Model) {
            $owner = $token->getAttribute(SanctumTenancy::column());
            $current = $this->tenancy->current()?->getTenantKey();

            $allowed = $owner === null
                ? $current === null || (bool) config('tenancy.integrations.sanctum.global_tokens', false)
                : (string) $owner === (string) $current;

            if (! $allowed) {
                throw new AccessDeniedHttpException('This access token does not belong to this tenant.');
            }
        }

        return $next($request);
    }
}
