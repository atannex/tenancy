<?php

declare(strict_types=1);

namespace Atannex\Tenancy\Http\Middleware;

use Atannex\Tenancy\Plans\Features;
use Atannex\Tenancy\Tenancy;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** `tenant.feature:sso,reports` — every listed feature must be active. */
final class EnsureFeature
{
    public function __construct(private readonly Tenancy $tenancy, private readonly Features $features) {}

    public function handle(Request $request, Closure $next, string ...$features): Response
    {
        $tenant = $this->tenancy->currentOrFail('tenant.feature');

        foreach ($features as $feature) {
            $this->features->assert($feature, $tenant);
        }

        return $next($request);
    }
}
