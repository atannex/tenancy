<?php

declare(strict_types=1);

namespace Atannex\Tenancy\Events;

use Atannex\Tenancy\Contracts\Tenant;
use Atannex\Tenancy\Models\Domain;

final readonly class DomainVerified
{
    public function __construct(public Tenant $tenant, public Domain $domain) {}
}
