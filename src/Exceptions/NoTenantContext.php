<?php

declare(strict_types=1);

namespace Atannex\Tenancy\Exceptions;

final class NoTenantContext extends TenancyException
{
    public static function forModel(string $model): self
    {
        return new self("[{$model}] is tenant data but no tenant is in context. Initialize a tenant, or wrap deliberate cross-tenant work in Tenancy::central().");
    }

    public static function forOperation(string $operation): self
    {
        return new self("{$operation} needs a tenant in context.");
    }
}
