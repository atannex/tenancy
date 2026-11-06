<?php

declare(strict_types=1);

namespace Atannex\Tenancy\Console;

use Atannex\Tenancy\Console\Concerns\FindsTenant;
use Atannex\Tenancy\Lifecycle\Maintenance;
use Illuminate\Console\Command;

final class UpCommand extends Command
{
    use FindsTenant;

    protected $signature = 'tenants:up {tenant}';

    protected $description = 'Bring one tenant out of maintenance mode';

    public function handle(): int
    {
        app(Maintenance::class)->up($tenant = $this->tenant());
        $this->components->info("Tenant [{$tenant->slug}] is up.");

        return self::SUCCESS;
    }
}
