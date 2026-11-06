<?php

declare(strict_types=1);

namespace Atannex\Tenancy\Console;

use Atannex\Tenancy\Console\Concerns\FindsTenant;
use Atannex\Tenancy\Lifecycle\TenantLifecycle;
use Illuminate\Console\Command;

final class SuspendCommand extends Command
{
    use FindsTenant;

    protected $signature = 'tenants:suspend {tenant} {--reason= : Why (kept on the tenant)}';

    protected $description = 'Suspend a tenant';

    public function handle(): int
    {
        app(TenantLifecycle::class)->suspend($tenant = $this->tenant(), $this->option('reason'));
        $this->components->info("Tenant [{$tenant->getTenantSlug()}] suspended.");

        return self::SUCCESS;
    }
}
