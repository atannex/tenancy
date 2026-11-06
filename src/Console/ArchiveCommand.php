<?php

declare(strict_types=1);

namespace Atannex\Tenancy\Console;

use Atannex\Tenancy\Console\Concerns\FindsTenant;
use Atannex\Tenancy\Lifecycle\TenantLifecycle;
use Illuminate\Console\Command;

final class ArchiveCommand extends Command
{
    use FindsTenant;

    protected $signature = 'tenants:archive {tenant} {--grace= : Days before it is purged (default tenancy.lifecycle.grace_days)}';

    protected $description = 'Archive a tenant; it is purged after the grace period';

    public function handle(): int
    {
        $grace = $this->option('grace');
        app(TenantLifecycle::class)->archive($tenant = $this->tenant(), $grace === null ? null : (int) $grace);
        $this->components->info("Tenant [{$tenant->slug}] archived; purge after {$tenant->purge_after}.");

        return self::SUCCESS;
    }
}
