<?php

declare(strict_types=1);

namespace Atannex\Tenancy\Console\Concerns;

use Atannex\Tenancy\Contracts\Tenant;
use Atannex\Tenancy\Enums\IsolationTier;
use Atannex\Tenancy\Isolation\TenantDatabase;
use Atannex\Tenancy\Jobs\RunTenantArtisan;
use Atannex\Tenancy\Tenancy;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Throwable;

/**
 * Runs a migrate-style artisan command once on the central database (for
 * shared-tier tenants) and once inside every selected dedicated tenant.
 *
 * @mixin Command
 */
trait RunsTenantMigrations
{
    use SelectsTenants;

    /** @return list<string> */
    protected function migrationPaths(): array
    {
        return array_values((array) config('tenancy.migrations.paths', []));
    }

    /** The tenant seeder: tenancy.provisioning.seeder, else Database\Seeders\TenantSeeder. */
    protected function tenantSeeder(): string
    {
        return (string) (config('tenancy.provisioning.seeder') ?: 'Database\\Seeders\\TenantSeeder');
    }

    /**
     * $seed runs the tenant seeder inside each tenant after its migrations,
     * never the central DatabaseSeeder that a bare "migrate --seed" would run.
     *
     * @param  array<string, mixed>  $parameters
     * @param  bool  $central  also run once on the central database for the shared tier
     */
    protected function runEverywhere(string $command, array $parameters, bool $central = true, bool $seed = false): int
    {
        $failures = 0;
        $tenancy = app(Tenancy::class);
        $seedShared = false;

        if ($central && $this->option('tier') !== 'schema' && $this->option('tier') !== 'database'
            && ($this->option('tenants') === [] || $this->selectedShared())) {
            $failed = $this->call1('central database (shared tier)', $command, $parameters + ['--database' => $tenancy->centralConnection()]);
            $failures += $failed;
            $seedShared = $seed && $failed === 0 && ! ($parameters['--pretend'] ?? false);
        }

        $this->eachTenant(function (Tenant $tenant) use ($command, $parameters, $seed, $seedShared, $tenancy, &$failures): void {
            if ($tenant->isolationTier() === IsolationTier::Shared) {
                // Shared-tier rows are stamped with the tenant the seeder runs in.
                if ($seedShared) {
                    $failures += $this->call1($tenant->getTenantSlug().' (seed)', 'db:seed', ['--class' => $this->tenantSeeder(), '--database' => $tenancy->centralConnection()]);
                }

                return;
            }

            $alias = app(TenantDatabase::class)->connectionName();

            if ($this->hasOption('queue') && $this->option('queue')) {
                RunTenantArtisan::dispatch($command, $parameters + ['--database' => $alias] + ($seed ? ['--seed' => true, '--seeder' => $this->tenantSeeder()] : []));
                $this->components->twoColumnDetail($tenant->getTenantSlug(), 'queued');

                return;
            }

            if (! app(TenantDatabase::class)->exists($tenant)) {
                $this->components->twoColumnDetail($tenant->getTenantSlug(), '<fg=red>database missing</>');
                $failures++;

                return;
            }

            $failed = $this->call1($tenant->getTenantSlug(), $command, $parameters + ['--database' => $alias]);
            $failures += $failed;

            if ($seed && $failed === 0 && ! ($parameters['--pretend'] ?? false)) {
                $failures += $this->call1($tenant->getTenantSlug().' (seed)', 'db:seed', ['--class' => $this->tenantSeeder(), '--database' => $alias]);
            }
        });

        return $failures === 0 ? self::SUCCESS : self::FAILURE;
    }

    /** @param array<string, mixed> $parameters */
    private function call1(string $label, string $command, array $parameters): int
    {
        $this->components->info($label);

        try {
            // migrate:status is read-only and takes no --force.
            $parameters += $command === 'migrate:status' ? [] : ['--force' => true];
            $code = Artisan::call($command, $parameters, $this->output);
        } catch (Throwable $e) {
            $this->components->error($e->getMessage());

            return 1;
        }

        return $code === 0 ? 0 : 1;
    }

    private function selectedShared(): bool
    {
        $found = false;

        app(Tenancy::class)->central(function () use (&$found): void {
            $found = $this->filterTenants(app(Tenancy::class)->query())->where('isolation', IsolationTier::Shared->value)->exists();
        });

        return $found;
    }

    /** @return array<string, mixed> */
    protected function pathParameters(): array
    {
        return ['--path' => $this->migrationPaths(), '--realpath' => true];
    }
}
