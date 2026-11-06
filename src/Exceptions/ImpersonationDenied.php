<?php

declare(strict_types=1);

namespace Atannex\Tenancy\Exceptions;

final class ImpersonationDenied extends TenancyException
{
    public static function notMember(): self
    {
        return new self('Only active members of the tenant can be impersonated.');
    }

    public static function owner(): self
    {
        return new self('Tenant owners cannot be impersonated.');
    }

    public static function invalid(): self
    {
        return new self('This impersonation link was not found.');
    }

    public static function used(): self
    {
        return new self('This impersonation link was already used.');
    }

    public static function expired(): self
    {
        return new self('This impersonation link has expired.');
    }

    public static function wrongTenant(): self
    {
        return new self('This impersonation link belongs to another tenant.');
    }

    public function getStatusCode(): int
    {
        return 403;
    }
}
