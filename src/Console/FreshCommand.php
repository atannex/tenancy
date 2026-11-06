<?php

declare(strict_types=1);

namespace Atannex\Tenancy\Console;

use Atannex\Tenancy\Console\Concerns\RunsTenantMigrations;
use Illuminate\Console\Command;

final class FreshCommand extends Command
{
    use RunsTenantMigrations;

    protected $description = 'Drop every table in dedicated tenant databases and migrate again (never the central database)';

    public function __construct()
    {
        $this->signature = 'tenants:fresh {--seed : Run the tenant seeder in each tenant afterwards} {--queue} '.$this->tenantOptions();

        parent::__construct();
    }

    public function handle(): int
    {
        return $this->runEverywhere('migrate:fresh', $this->pathParameters(), central: false, seed: (bool) $this->option('seed'));
    }
}
