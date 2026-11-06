<?php

declare(strict_types=1);

namespace Atannex\Tenancy\Events;

use Atannex\Tenancy\Contracts\Tenant;
use Atannex\Tenancy\Models\Membership;

final readonly class MemberSuspended
{
    public function __construct(public Tenant $tenant, public Membership $membership) {}
}
