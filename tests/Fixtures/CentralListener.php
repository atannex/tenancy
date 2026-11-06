<?php

declare(strict_types=1);

namespace Atannex\Tenancy\Tests\Fixtures;

use Atannex\Tenancy\Attributes\Central;
use Illuminate\Contracts\Queue\ShouldQueue;

#[Central]
final class CentralListener implements ShouldQueue
{
    public function handle(object $event): void {}
}
