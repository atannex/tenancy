<?php

declare(strict_types=1);

namespace Atannex\Tenancy\Jobs;

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
        if (Artisan::call($this->command, $this->parameters + ['--force' => true]) !== 0) {
            throw new RuntimeException("[{$this->command}] failed: ".Artisan::output());
        }
    }
}
