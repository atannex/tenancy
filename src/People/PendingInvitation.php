<?php

declare(strict_types=1);

namespace Atannex\Tenancy\People;

use Atannex\Tenancy\Models\Invitation;

/** A freshly issued invitation and its plain token — the only time the token is known. */
final readonly class PendingInvitation
{
    public function __construct(public Invitation $invitation, public string $token) {}
}
