<?php

declare(strict_types=1);

namespace Atannex\Tenancy\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;
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

    /** What clients see outside debug mode: no class names, slugs or keys. */
    public function publicMessage(): string
    {
        return Response::$statusTexts[$this->getStatusCode()] ?? 'Error';
    }

    /** JSON errors outside debug mode carry only the public message; HTML uses your error views. */
    public function render(Request $request): ?Response
    {
        if (config('app.debug') || ! $request->expectsJson()) {
            return null;
        }

        return new JsonResponse(['message' => $this->publicMessage()], $this->getStatusCode(), $this->getHeaders());
    }

    /** Returning true tells Laravel the exception is handled (not logged). */
    public function report(): bool
    {
        return $this->getStatusCode() < 500;
    }
}
