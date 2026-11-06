<?php

declare(strict_types=1);

namespace Atannex\Tenancy\Events;

use Atannex\Tenancy\Contracts\Tenant;

final readonly class TenantProvisioned
{
    public function __construct(public Tenant $tenant) {}
}
