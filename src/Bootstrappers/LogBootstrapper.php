<?php

declare(strict_types=1);

namespace Atannex\Tenancy\Bootstrappers;

use Atannex\Tenancy\Contracts\Bootstrapper;
use Atannex\Tenancy\Contracts\Tenant;
use Illuminate\Support\Facades\Context;

/** Adds tenant_id and tenant_slug to every log line (via Laravel's Context). */
final class LogBootstrapper implements Bootstrapper
{
    public function bootstrap(Tenant $tenant): void
    {
        Context::add(['tenant_id' => $tenant->getTenantKey(), 'tenant_slug' => $tenant->getTenantSlug()]);
    }

    public function revert(): void
    {
        Context::forget(['tenant_id', 'tenant_slug']);
    }
}
