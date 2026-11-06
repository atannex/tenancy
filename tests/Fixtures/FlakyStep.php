<?php

declare(strict_types=1);

namespace Atannex\Tenancy\Tests\Fixtures;

use Atannex\Tenancy\Contracts\ProvisioningStep;
use Atannex\Tenancy\Contracts\Tenant;
use RuntimeException;

final class FlakyStep implements ProvisioningStep
{
    public static int $failures = 0;

    /** @var list<string> */
    public static array $log = [];

    public function handle(Tenant $tenant, array $options): void
    {
        if (self::$failures > 0) {
            self::$failures--;

            throw new RuntimeException('flaky');
        }

        self::$log[] = 'handle';
    }

    public function rollback(Tenant $tenant, array $options): void
    {
        self::$log[] = 'rollback';
    }
}
