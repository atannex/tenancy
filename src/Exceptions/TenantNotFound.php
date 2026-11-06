<?php

declare(strict_types=1);

namespace Atannex\Tenancy\Exceptions;

final class TenantNotFound extends TenancyException
{
    public static function forKey(string $key): self
    {
        return new self("No tenant matches [{$key}].");
    }

    public static function forHost(string $host): self
    {
        return new self("No tenant serves [{$host}].");
    }

    public function getStatusCode(): int
    {
        return 404;
    }
}
