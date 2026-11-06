<?php

declare(strict_types=1);

namespace Atannex\Tenancy\Console;

use Atannex\Tenancy\Console\Concerns\RunsTenantMigrations;
use Illuminate\Console\Command;

final class MigrateCommand extends Command
{
    use RunsTenantMigrations;

    protected $description = 'Run tenant migrations';

    public function __construct()
    {
        $this->signature = 'tenants:migrate {--seed : Seed after migrating} {--step : Migrations can be rolled back individually} {--pretend} {--queue : Queue one job per dedicated tenant} '.$this->tenantOptions();

        parent::__construct();
    }

    public function handle(): int
    {
        return $this->runEverywhere('migrate', $this->pathParameters() + array_filter(['--seed' => $this->option('seed'), '--step' => $this->option('step'), '--pretend' => $this->option('pretend')]), central: true);
    }
}
