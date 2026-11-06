<?php

declare(strict_types=1);

namespace Atannex\Tenancy\Http\Middleware;

/** `tenant.optional` — resolve a tenant if one matches, otherwise carry on centrally. */
final class InitializeTenancyIfPossible extends InitializeTenancy
{
    protected bool $required = false;
}
