<?php

declare(strict_types=1);

namespace Atannex\Tenancy\Exceptions;

use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * Every tenancy failure. Being an HTTP exception, it renders with its own
 * status when it escapes a request; client errors are not logged.
 */
abstract class TenancyException extends RuntimeException implements HttpExceptionInterface
{
    public function getStatusCode(): int
    {
        return 500;
    }

    /** @return array<string, string> */
    public function getHeaders(): array
    {
        return [];
    }

    /** Returning true tells Laravel the exception is handled (not logged). */
    public function report(): bool
    {
        return $this->getStatusCode() < 500;
    }
}
