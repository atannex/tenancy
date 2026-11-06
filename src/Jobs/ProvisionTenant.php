<?php

declare(strict_types=1);

namespace Atannex\Tenancy\Jobs;

use Atannex\Tenancy\Attributes\Central;
use Atannex\Tenancy\Lifecycle\Provisioner;
use Atannex\Tenancy\Tenancy;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

/** Steps retry themselves, so the job runs once. */
#[Central]
final class ProvisionTenant implements ShouldQueue
{
    use Dispatchable, Queueable;

    public int $tries = 1;

    public function __construct(public readonly string $tenant) {}

    public function handle(Tenancy $tenancy, Provisioner $provisioner): void
    {
        if ($tenant = $tenancy->findByKey($this->tenant)) {
            $provisioner->provision($tenant);
        }
    }
}
