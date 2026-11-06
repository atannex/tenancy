<?php

declare(strict_types=1);

namespace Atannex\Tenancy\Http;

use Atannex\Tenancy\Http\Controllers\Api\DomainsController;
use Atannex\Tenancy\Http\Controllers\Api\TenantsController;
use Atannex\Tenancy\Http\Middleware\EnsureCentral;
use Illuminate\Routing\Router;

/** The optional management API (tenancy.api). */
final class ApiRoutes
{
    public static function register(Router $router): void
    {
        $router->prefix((string) config('tenancy.api.prefix', 'tenancy-api'))
            ->middleware(array_merge((array) config('tenancy.api.middleware', []), [EnsureCentral::class.':all']))
            ->name('tenancy.api.')
            ->group(function (Router $router): void {
                $router->get('tenants', [TenantsController::class, 'index'])->name('tenants.index');
                $router->post('tenants', [TenantsController::class, 'store'])->name('tenants.store');
                $router->get('tenants/{tenant}', [TenantsController::class, 'show'])->name('tenants.show');
                $router->post('tenants/{tenant}/suspend', [TenantsController::class, 'suspend'])->name('tenants.suspend');
                $router->post('tenants/{tenant}/reactivate', [TenantsController::class, 'reactivate'])->name('tenants.reactivate');
                $router->post('tenants/{tenant}/archive', [TenantsController::class, 'archive'])->name('tenants.archive');
                $router->post('tenants/{tenant}/restore', [TenantsController::class, 'restore'])->name('tenants.restore');
                $router->put('tenants/{tenant}/plan', [TenantsController::class, 'plan'])->name('tenants.plan');
                $router->get('tenants/{tenant}/members', [TenantsController::class, 'members'])->name('tenants.members');
                $router->get('tenants/{tenant}/usage', [TenantsController::class, 'usage'])->name('tenants.usage');
                $router->get('tenants/{tenant}/domains', [DomainsController::class, 'index'])->name('domains.index');
                $router->post('tenants/{tenant}/domains', [DomainsController::class, 'store'])->name('domains.store');
                $router->post('domains/{domain}/verify', [DomainsController::class, 'verify'])->name('domains.verify');
                $router->post('domains/{domain}/primary', [DomainsController::class, 'primary'])->name('domains.primary');
                $router->delete('domains/{domain}', [DomainsController::class, 'destroy'])->name('domains.destroy');
            });
    }
}
