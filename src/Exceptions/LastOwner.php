<?php

declare(strict_types=1);

namespace Atannex\Tenancy\Exceptions;

final class LastOwner extends TenancyException
{
    public static function make(): self
    {
        return new self('A tenant must keep at least one active owner.');
    }

    public function getStatusCode(): int
    {
        return 409;
    }
}
