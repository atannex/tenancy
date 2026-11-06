<?php

declare(strict_types=1);

namespace Atannex\Tenancy\Console;

use Atannex\Tenancy\Console\Concerns\RunsTenantMigrations;
use Illuminate\Console\Command;

final class RollbackCommand extends Command
{
    use RunsTenantMigrations;

    protected $description = 'Roll back tenant migrations';

    public function __construct()
    {
        $this->signature = 'tenants:rollback {--step= : Number of migrations to roll back} {--queue} '.$this->tenantOptions();

        parent::__construct();
    }

    public function handle(): int
    {
        return $this->runEverywhere('migrate:rollback', $this->pathParameters() + array_filter(['--step' => $this->option('step')]), central: true);
    }
}
