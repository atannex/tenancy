<?php

declare(strict_types=1);

namespace Atannex\Tenancy\People\Http;

use Atannex\Tenancy\People\Invitations;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class AcceptInvitationController
{
    public function __invoke(Request $request, string $token, Invitations $invitations): RedirectResponse
    {
        $invitations->accept($token, $request->user());

        return redirect()->to((string) config('tenancy.people.routes.after_accept', '/'));
    }
}
