<?php

declare(strict_types=1);

namespace Atannex\Tenancy\Jobs;

use Atannex\Tenancy\Enums\IsolationTier;
use Atannex\Tenancy\Isolation\TenantDatabase;
use Atannex\Tenancy\Tenancy;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Artisan;
use RuntimeException;

/** An artisan command with parameters, run inside the tenant it was dispatched from. */
final class RunTenantArtisan implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /** @param array<string, mixed> $parameters */
    public function __construct(public readonly string $command, public readonly array $parameters = []) {}

    public function handle(): void
    {
        $alias = app(TenantDatabase::class)->connectionName();
        $tenant = app(Tenancy::class)->current();

        // Queued for a dedicated tenant that has since moved to the shared tier: never run it on a stale connection.
        if (($this->parameters['--database'] ?? null) === $alias && ($tenant === null || $tenant->isolationTier() === IsolationTier::Shared)) {
            throw new RuntimeException("[{$this->command}] targets the tenant database, but the tenant is on the shared tier now.");
        }

        if (Artisan::call($this->command, $this->parameters + ['--force' => true]) !== 0) {
            throw new RuntimeException("[{$this->command}] failed: ".Artisan::output());
        }
    }
}
