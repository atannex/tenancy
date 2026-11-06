<?php

declare(strict_types=1);

namespace Atannex\Tenancy\Events;

use Atannex\Tenancy\Contracts\Tenant;

final readonly class DomainRemoved
{
    public function __construct(public Tenant $tenant, public string $host) {}
}
