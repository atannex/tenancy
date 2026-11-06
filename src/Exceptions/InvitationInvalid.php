<?php

declare(strict_types=1);

namespace Atannex\Tenancy\Exceptions;

final class InvitationInvalid extends TenancyException
{
    private int $status = 410;

    public static function notFound(): self
    {
        $e = new self('This invitation was not found.');
        $e->status = 404;

        return $e;
    }

    public static function accepted(): self
    {
        return new self('This invitation was already accepted.');
    }

    public static function revoked(): self
    {
        return new self('This invitation was revoked.');
    }

    public static function expired(): self
    {
        return new self('This invitation has expired.');
    }

    public static function emailMismatch(): self
    {
        $e = new self('This invitation was sent to another email address.');
        $e->status = 403;

        return $e;
    }

    public function getStatusCode(): int
    {
        return $this->status;
    }
}
