<?php

declare(strict_types=1);

namespace Atannex\Tenancy\Exceptions;

final class FeatureUnavailable extends TenancyException
{
    public static function for(string $feature): self
    {
        return new self("The [{$feature}] feature is not available on this plan.");
    }

    public function getStatusCode(): int
    {
        return 403;
    }
}
