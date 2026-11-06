<?php

declare(strict_types=1);

namespace Atannex\Tenancy\Tests\Fixtures;

use Atannex\Tenancy\Attributes\Central;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

#[Central]
final class RecordCentralJob implements ShouldQueue
{
    use Dispatchable, Queueable;

    public function handle(): void
    {
        RecordTenantJob::$seen[] = tenant()?->getTenantSlug();
    }
}
