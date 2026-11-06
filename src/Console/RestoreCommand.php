<?php

declare(strict_types=1);

namespace Atannex\Tenancy\Console;

use Atannex\Tenancy\Console\Concerns\FindsTenant;
use Atannex\Tenancy\Lifecycle\TenantLifecycle;
use Illuminate\Console\Command;

final class RestoreCommand extends Command
{
    use FindsTenant;

    protected $signature = 'tenants:restore {tenant}';

    protected $description = 'Restore an archived tenant before it is purged';

    public function handle(): int
    {
        app(TenantLifecycle::class)->restore($tenant = $this->tenant());
        $this->components->info("Tenant [{$tenant->slug}] restored.");

        return self::SUCCESS;
    }
}
