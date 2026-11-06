<?php

declare(strict_types=1);

namespace Atannex\Tenancy\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Artisan;

/** Runs an artisan command; dispatched inside a tenant, so it runs inside it. */
final class RunTenantCommand implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public function __construct(public readonly string $command) {}

    public function handle(): void
    {
        Artisan::call($this->command);
    }
}
