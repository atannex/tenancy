<?php

declare(strict_types=1);

namespace Atannex\Tenancy\Http\Middleware;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `tenant.session` / `tenant.session:session,user` — identify the tenant from the
 * session or the signed-in user. Runs after StartSession and authentication
 * but still before route model binding. (SessionBootstrapper cannot scope a
 * session that is already started, so do not combine the two.)
 */
final class InitializeTenancyAfterSession extends InitializeTenancy
{
    protected bool $late = true;

    public function handle(Request $request, \Closure $next, string ...$resolvers): Response
    {
        return parent::handle($request, $next, ...($resolvers ?: ['session', 'user']));
    }
}
