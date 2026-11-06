<?php

declare(strict_types=1);

namespace Atannex\Tenancy\People\Http;

use Atannex\Tenancy\People\Impersonations;
use Illuminate\Http\RedirectResponse;

final class StopImpersonationController
{
    public function __invoke(Impersonations $impersonations): RedirectResponse
    {
        $impersonations->stop();

        return redirect()->to('/');
    }
}
