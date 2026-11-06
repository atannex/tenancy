<?php

declare(strict_types=1);

namespace Atannex\Tenancy\Console;

use Atannex\Tenancy\Tenancy;
use Illuminate\Console\Command;

final class ListTenantsCommand extends Command
{
    protected $signature = 'tenants:list {--status= : Only tenants with this status}';

    protected $description = 'List tenants';

    public function handle(Tenancy $tenancy): int
    {
        $query = $tenancy->query()->withCount('domains')->orderBy('slug');

        if ($status = $this->option('status')) {
            $query->where('status', $status);
        }

        $this->table(
            ['Key', 'Slug', 'Name', 'Status', 'Isolation', 'Domains'],
            $query->get()->map(fn ($t) => [$t->getTenantKey(), $t->getTenantSlug(), $t->getAttribute('name'), $t->tenantStatus()->value, $t->isolationTier()->value, $t->getAttribute('domains_count')])->all(),
        );

        return self::SUCCESS;
    }
}
