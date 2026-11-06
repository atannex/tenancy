<?php

declare(strict_types=1);

namespace Atannex\Tenancy\Exceptions;

final class QuotaExceeded extends TenancyException
{
    public static function for(string $quota, ?int $limit): self
    {
        return new self("The [{$quota}] quota of {$limit} is used up.");
    }

    public function getStatusCode(): int
    {
        return (int) config('tenancy.quotas.status', 402);
    }
}
