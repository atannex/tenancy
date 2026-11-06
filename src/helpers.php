<?php

declare(strict_types=1);

use Atannex\Tenancy\Contracts\Tenant;
use Atannex\Tenancy\Tenancy;

if (! function_exists('tenant')) {
    /** The tenant in context, or null in central code. */
    function tenant(): ?Tenant
    {
        return app(Tenancy::class)->current();
    }
}
