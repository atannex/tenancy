<?php

declare(strict_types=1);

namespace Atannex\Tenancy\Console;

use Atannex\Tenancy\Console\Concerns\FindsTenant;
use Atannex\Tenancy\Enums\IsolationTier;
use Atannex\Tenancy\Lifecycle\Promoter;
use Illuminate\Console\Command;
use Throwable;

final class PromoteCommand extends Command
{
    use FindsTenant;

    protected $signature = 'tenants:promote {tenant} {--to= : shared, schema or database} {--delete-source : Delete the data on the old tier once the move is verified}';

    protected $description = 'Move a tenant to another isolation tier';

    public function handle(Promoter $promoter): int
    {
        $to = IsolationTier::tryFrom((string) $this->option('to'));

        if ($to === null) {
            $this->components->error('Pass --to=shared, --to=schema or --to=database.');

            return self::FAILURE;
        }

        try {
            $promoter->promote($tenant = $this->tenant(), $to, (bool) $this->option('delete-source'));
        } catch (Throwable $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $this->components->info("Tenant [{$tenant->getTenantSlug()}] is now on the {$to->value} tier.");

        return self::SUCCESS;
    }
}
