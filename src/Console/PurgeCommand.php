<?php

declare(strict_types=1);

namespace Atannex\Tenancy\Console;

use Atannex\Tenancy\Enums\TenantStatus;
use Atannex\Tenancy\Lifecycle\TenantLifecycle;
use Atannex\Tenancy\Tenancy;
use Illuminate\Console\Command;
use Throwable;

final class PurgeCommand extends Command
{
    protected $signature = 'tenants:purge {tenant? : An archived tenant to purge now} {--due : Purge archived tenants whose grace period is over} {--force : Do not ask}';

    protected $description = 'Permanently delete archived tenants and everything they own';

    public function handle(Tenancy $tenancy, TenantLifecycle $lifecycle): int
    {
        $tenants = $this->argument('tenant')
            ? collect([$tenancy->findOrFail((string) $this->argument('tenant'))])
            : ($this->option('due')
                ? $tenancy->query()->where('status', TenantStatus::Archived->value)->where('purge_after', '<=', now())->get()
                : collect());

        if ($tenants->isEmpty()) {
            $this->components->info($this->argument('tenant') || $this->option('due') ? 'Nothing to purge.' : 'Name a tenant or pass --due.');

            return self::SUCCESS;
        }

        if (! $this->option('force') && ! $this->option('due') && ! $this->confirm('Permanently delete '.$tenants->pluck('slug')->implode(', ').'?')) {
            return self::FAILURE;
        }

        $failed = 0;

        foreach ($tenants as $tenant) {
            try {
                $lifecycle->purge($tenant);
                $this->components->twoColumnDetail($tenant->slug, '<fg=green>purged</>');
            } catch (Throwable $e) {
                $failed++;
                $this->components->twoColumnDetail($tenant->slug, '<fg=red>'.$e->getMessage().'</>');
            }
        }

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
