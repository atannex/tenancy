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

    public static function foreignRow(string $model): self
    {
        return new self("This [{$model}] belongs to another tenant.");
    }

    public static function upsertKey(string $model, string $column): self
    {
        return new self("Upserts of [{$model}] must include [{$column}] in uniqueBy, or they can overwrite another tenant's rows.");
    }

    public static function centralOnly(string $model, string $operation): self
    {
        return new self("{$operation}() on [{$model}] reaches every tenant's rows; run it in Tenancy::central().");
    }

    public function getStatusCode(): int
    {
        return 409;
    }
}
