<?php

declare(strict_types=1);

namespace Atannex\Tenancy\Events;

use Atannex\Tenancy\Contracts\Tenant;

/** The purge is about to remove the tenant and everything it owns; the tenant still exists. */
final readonly class TenantPurging
{
    public function __construct(public Tenant $tenant) {}
}
