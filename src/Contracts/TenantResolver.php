<?php

declare(strict_types=1);

namespace Atannex\Tenancy\Contracts;

use Illuminate\Http\Request;

interface TenantResolver
{
    public function resolve(Request $request): ?Tenant;
}
