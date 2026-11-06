<?php

declare(strict_types=1);

namespace Atannex\Tenancy\People\Http;

use Atannex\Tenancy\People\Impersonations;
use Illuminate\Http\RedirectResponse;

final class EnterImpersonationController
{
    public function __invoke(string $token, Impersonations $impersonations): RedirectResponse
    {
        $impersonations->enter($token);

        return redirect()->to((string) config('tenancy.people.routes.after_impersonate', '/'));
    }
}
