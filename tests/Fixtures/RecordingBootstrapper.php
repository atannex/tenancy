<?php

declare(strict_types=1);

namespace Atannex\Tenancy\Tests\Fixtures;

use Atannex\Tenancy\Contracts\Bootstrapper;
use Atannex\Tenancy\Contracts\Tenant;

final class RecordingBootstrapper implements Bootstrapper
{
    /** @var list<string> */
    public static array $log = [];

    public static ?string $failFor = null;

    public static bool $failRevert = false;

    public function bootstrap(Tenant $tenant): void
    {
        if (self::$failFor === $tenant->getTenantSlug()) {
            throw new \RuntimeException('bootstrap failed');
        }

        self::$log[] = 'up:'.$tenant->getTenantSlug();
    }

    public function revert(): void
    {
        if (self::$failRevert) {
            self::$failRevert = false;

            throw new \RuntimeException('revert failed');
        }

        self::$log[] = 'down';
    }
}
