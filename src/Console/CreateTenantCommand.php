<?php

declare(strict_types=1);

namespace Atannex\Tenancy\Console;

use Atannex\Tenancy\Domains\Domains;
use Atannex\Tenancy\Enums\DomainType;
use Atannex\Tenancy\Enums\IsolationTier;
use Atannex\Tenancy\Enums\TenantStatus;
use Atannex\Tenancy\Tenancy;
use Illuminate\Console\Command;
use Throwable;

final class CreateTenantCommand extends Command
{
    protected $signature = 'tenants:create {name : Display name}
        {--slug= : URL-safe slug (default: from the name)}
        {--domain=* : Domains; the first is primary}
        {--tier= : shared, schema or database (default tenancy.database.default_tier)}
        {--plan= : Plan key}
        {--owner= : User key of the owner}';

    protected $description = 'Create and provision a tenant';

    public function handle(Tenancy $tenancy, Domains $domains): int
    {
        $hosts = array_values((array) $this->option('domain'));

        try {
            $tenant = $tenancy->create(array_filter([
                'name' => $this->argument('name'),
                'slug' => $this->option('slug'),
                'plan' => $this->option('plan'),
                'isolation' => $this->option('tier') ? IsolationTier::from((string) $this->option('tier')) : null,
            ]), array_filter([
                // An operator at the console vouches for these domains.
                'domain' => $hosts[0] ?? null,
                'verified' => true,
                'owner' => $this->option('owner'),
            ]));

            foreach (array_slice($hosts, 1) as $host) {
                $domains->add($tenant, $host, DomainType::Alias, verified: true);
            }
        } catch (Throwable $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        if ($tenant->tenantStatus() === TenantStatus::Failed) {
            $this->components->error("Tenant [{$tenant->getTenantSlug()}] was created but provisioning failed; fix the cause and run tenants:retry.");

            return self::FAILURE;
        }

        $this->components->info("Tenant [{$tenant->getTenantSlug()}] created with key [{$tenant->getTenantKey()}] ({$tenant->tenantStatus()->value}).");

        return self::SUCCESS;
    }
}
