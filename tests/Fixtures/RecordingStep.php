<?php

declare(strict_types=1);

namespace Atannex\Tenancy\Tests\Fixtures;

use Atannex\Tenancy\Contracts\ProvisioningStep;
use Atannex\Tenancy\Contracts\Tenant;

final class RecordingStep implements ProvisioningStep
{
    public function handle(Tenant $tenant, array $options): void
    {
        FlakyStep::$log[] = 'record:'.($options['colour'] ?? '-');
    }

    public function rollback(Tenant $tenant, array $options): void
    {
        FlakyStep::$log[] = 'unrecord';
    }
}
