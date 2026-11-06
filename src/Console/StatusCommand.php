<?php

declare(strict_types=1);

namespace Atannex\Tenancy\Console;

use Atannex\Tenancy\Console\Concerns\RunsTenantMigrations;
use Illuminate\Console\Command;

final class StatusCommand extends Command
{
    use RunsTenantMigrations;

    protected $description = 'Show tenant migration status';

    public function __construct()
    {
        $this->signature = 'tenants:status {--queue} '.$this->tenantOptions();

        parent::__construct();
    }

    public function handle(): int
    {
        return $this->runEverywhere('migrate:status', $this->pathParameters(), central: true);
    }
}
