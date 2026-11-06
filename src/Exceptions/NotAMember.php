<?php

declare(strict_types=1);

namespace Atannex\Tenancy\Exceptions;

final class NotAMember extends TenancyException
{
    public static function of(string $tenant): self
    {
        return new self("The user is not an active member of tenant [{$tenant}].");
    }

    public function getStatusCode(): int
    {
        return 403;
    }
}
