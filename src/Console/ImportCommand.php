<?php

declare(strict_types=1);

namespace Atannex\Tenancy\Console;

use Atannex\Tenancy\Enums\IsolationTier;
use Atannex\Tenancy\Lifecycle\Importer;
use Illuminate\Console\Command;
use Throwable;

final class ImportCommand extends Command
{
    protected $signature = 'tenants:import {path : A tenants:export zip} {--slug=} {--name=} {--tier= : shared, schema or database}';

    protected $description = 'Import a tenant export into a new tenant';

    public function handle(Importer $importer): int
    {
        try {
            $tenant = $importer->import((string) $this->argument('path'), array_filter([
                'slug' => $this->option('slug'),
                'name' => $this->option('name'),
                'isolation' => $this->option('tier') ? IsolationTier::from($this->option('tier')) : null,
            ]));
        } catch (Throwable $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $this->components->info("Imported as tenant [{$tenant->slug}] ({$tenant->getTenantKey()}).");

        return self::SUCCESS;
    }
}
