<?php

declare(strict_types=1);

namespace Atannex\Tenancy\Events;

use Atannex\Tenancy\Contracts\Tenant;
use Atannex\Tenancy\Models\Invitation;

final readonly class InvitationRevoked
{
    public function __construct(public Tenant $tenant, public Invitation $invitation) {}
}
