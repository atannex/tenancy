<?php

declare(strict_types=1);

namespace Atannex\Tenancy\People;

use Atannex\Tenancy\People\Http\AcceptInvitationController;
use Atannex\Tenancy\People\Http\EnterImpersonationController;
use Atannex\Tenancy\People\Http\StopImpersonationController;
use Illuminate\Support\Facades\Route;

/** Optional routes (tenancy.people.routes.enabled) for invitations and impersonation. */
final class Routes
{
    private bool $registered = false;

    public function register(): void
    {
        if ($this->registered || ! config('tenancy.people.routes.enabled', false)) {
            return;
        }

        $this->registered = true;

        Route::middleware((array) config('tenancy.people.routes.middleware', ['web']))
            ->prefix((string) config('tenancy.people.routes.prefix', 'tenancy'))
            ->name('tenancy.')
            ->group(function (): void {
                Route::get('invitations/{token}', AcceptInvitationController::class)->middleware('auth')->name('invitations.accept');
                Route::get('impersonate/{token}', EnterImpersonationController::class)->middleware('tenant')->name('impersonate');
                Route::post('impersonate/stop', StopImpersonationController::class)->middleware('tenant')->name('impersonate.stop');
            });
    }
}
