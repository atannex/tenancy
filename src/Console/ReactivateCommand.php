<?php

declare(strict_types=1);

namespace Atannex\Tenancy\Console;

use Atannex\Tenancy\Console\Concerns\FindsTenant;
use Atannex\Tenancy\Lifecycle\TenantLifecycle;
use Illuminate\Console\Command;

final class ReactivateCommand extends Command
{
    use FindsTenant;

    protected $signature = 'tenants:reactivate {tenant}';

    protected $description = 'Reactivate a suspended tenant';

    public function handle(): int
    {
        app(TenantLifecycle::class)->reactivate($tenant = $this->tenant());
        $this->components->info("Tenant [{$tenant->slug}] reactivated.");

        return self::SUCCESS;
    }
}
