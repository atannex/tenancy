<?php

declare(strict_types=1);

namespace Atannex\Tenancy\Exceptions;

final class TenantMismatch extends TenancyException
{
    public static function immutable(string $model): self
    {
        return new self("The tenant of a [{$model}] can never change.");
    }

    public static function foreign(string $model): self
    {
        return new self("Cannot create a [{$model}] for another tenant from inside a tenant.");
    }

    public function getStatusCode(): int
    {
        return 409;
    }
}
