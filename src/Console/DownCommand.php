<?php

declare(strict_types=1);

namespace Atannex\Tenancy\Console;

use Atannex\Tenancy\Console\Concerns\FindsTenant;
use Atannex\Tenancy\Lifecycle\Maintenance;
use Illuminate\Console\Command;

final class DownCommand extends Command
{
    use FindsTenant;

    protected $signature = 'tenants:down {tenant} {--secret= : Bypass with ?tenancy_secret=...} {--retry= : Retry-After seconds} {--message=}';

    protected $description = 'Put one tenant into maintenance mode';

    public function handle(): int
    {
        app(Maintenance::class)->down($tenant = $this->tenant(), [
            'secret' => $this->option('secret'),
            'retry' => $this->option('retry') === null ? null : (int) $this->option('retry'),
            'message' => $this->option('message'),
        ]);
        $this->components->info("Tenant [{$tenant->slug}] is down for maintenance.");

        return self::SUCCESS;
    }
}
