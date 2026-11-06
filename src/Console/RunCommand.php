<?php

declare(strict_types=1);

namespace Atannex\Tenancy\Console;

use Atannex\Tenancy\Console\Concerns\SelectsTenants;
use Atannex\Tenancy\Jobs\RunTenantCommand;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

final class RunCommand extends Command
{
    use SelectsTenants;

    protected $description = 'Run an artisan command inside each tenant';

    public function __construct()
    {
        $this->signature = 'tenants:run {commandname : The artisan command with its arguments, quoted} {--queue : Queue one job per tenant} '.$this->tenantOptions();

        parent::__construct();
    }

    public function handle(): int
    {
        $command = (string) $this->argument('commandname');
        $failed = 0;

        $count = $this->eachTenant(function ($tenant) use ($command, &$failed): void {
            if ($this->option('queue')) {
                RunTenantCommand::dispatch($command);

                return;
            }

            $this->components->task("{$tenant->getTenantSlug()}: {$command}", function () use ($command, &$failed) {
                $ok = Artisan::call($command) === self::SUCCESS;
                $ok || $failed++;

                return $ok;
            });
        });

        $this->components->info(($this->option('queue') ? 'Queued for ' : 'Ran for ')."{$count} tenant(s).");

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
