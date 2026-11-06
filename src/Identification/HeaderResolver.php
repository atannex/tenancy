<?php

declare(strict_types=1);

namespace Atannex\Tenancy\Identification;

use Atannex\Tenancy\Contracts\Tenant;
use Atannex\Tenancy\Contracts\TenantResolver;
use Atannex\Tenancy\Tenancy;
use Illuminate\Http\Request;

final class HeaderResolver implements TenantResolver
{
    public function __construct(private readonly Tenancy $tenancy) {}

    public function resolve(Request $request): ?Tenant
    {
        $value = trim((string) $request->header((string) config('tenancy.identification.header', 'X-Tenant')));

        return $value === '' ? null : $this->tenancy->find($value);
    }
}
