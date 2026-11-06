<?php

declare(strict_types=1);

namespace Atannex\Tenancy\Tests\Fixtures;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

final class RecordTenantJob implements ShouldQueue
{
    use Dispatchable, Queueable;

    /** @var list<string|null> */
    public static array $seen = [];

    public function handle(): void
    {
        self::$seen[] = tenant()?->getTenantSlug();
    }
}
