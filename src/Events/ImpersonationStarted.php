<?php

declare(strict_types=1);

namespace Atannex\Tenancy\Events;

use Atannex\Tenancy\Contracts\Tenant;
use Atannex\Tenancy\Models\Impersonation;

final readonly class ImpersonationStarted
{
    public function __construct(public Tenant $tenant, public Impersonation $impersonation) {}
}
