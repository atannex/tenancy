<?php

declare(strict_types=1);

namespace Atannex\Tenancy\Events;

use Atannex\Tenancy\Contracts\Tenant;
use Illuminate\Http\Request;

final readonly class TenantResolved
{
    public function __construct(public Tenant $tenant, public string $resolver, public Request $request) {}
}
