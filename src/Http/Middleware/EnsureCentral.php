<?php

declare(strict_types=1);

namespace Atannex\Tenancy\Http\Middleware;

use Atannex\Tenancy\Identification\Hosts;
use Atannex\Tenancy\Tenancy;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** `central` — central hosts only, run inside an explicit central frame. */
final class EnsureCentral
{
    public function __construct(private readonly Tenancy $tenancy) {}

    public function handle(Request $request, Closure $next): Response
    {
        $central = (array) config('tenancy.identification.central_domains', []);

        abort_if($central !== [] && ! Hosts::isCentral($request->getHost(), $central), 404);

        return $this->tenancy->central(fn () => $next($request));
    }
}
