<?php

declare(strict_types=1);

namespace Atannex\Tenancy\Console;

use Atannex\Tenancy\Enums\DomainType;
use Atannex\Tenancy\Tenancy;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

final class CreateTenantCommand extends Command
{
    protected $signature = 'tenants:create {name : Display name} {--slug= : URL-safe slug (default: from the name)} {--domain=* : Domains; the first is primary}';

    protected $description = 'Create a tenant and its domains';

    public function handle(Tenancy $tenancy): int
    {
        try {
            $tenant = DB::connection($tenancy->centralConnection())->transaction(function () use ($tenancy) {
                $model = $tenancy->model();
                $tenant = $model::create(['name' => $this->argument('name'), 'slug' => $this->option('slug')]);

                foreach (array_values((array) $this->option('domain')) as $i => $host) {
                    $tenant->domains()->create(['host' => $host, 'type' => $i === 0 ? DomainType::Primary : DomainType::Alias]);
                }

                return $tenant;
            });
        } catch (Throwable $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $this->components->info("Tenant [{$tenant->getTenantSlug()}] created with key [{$tenant->getTenantKey()}].");

        return self::SUCCESS;
    }
}
