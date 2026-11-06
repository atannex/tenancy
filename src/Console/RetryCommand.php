<?php

declare(strict_types=1);

namespace Atannex\Tenancy\Console;

use Atannex\Tenancy\Console\Concerns\FindsTenant;
use Atannex\Tenancy\Lifecycle\Provisioner;
use Illuminate\Console\Command;

final class RetryCommand extends Command
{
    use FindsTenant;

    protected $signature = 'tenants:retry {tenant}';

    protected $description = 'Retry provisioning a failed tenant';

    public function handle(): int
    {
        $ok = app(Provisioner::class)->provision($tenant = $this->tenant());
        $ok ? $this->components->info("Tenant [{$tenant->slug}] provisioned.") : $this->components->error("Provisioning [{$tenant->slug}] failed again; see tenant_provisioning_steps.");

        return $ok ? self::SUCCESS : self::FAILURE;
    }
}
