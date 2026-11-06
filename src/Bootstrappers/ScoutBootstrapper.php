<?php

declare(strict_types=1);

namespace Atannex\Tenancy\Bootstrappers;

use Atannex\Tenancy\Contracts\Bootstrapper;
use Atannex\Tenancy\Contracts\Tenant;

/** Search index names per tenant (Laravel Scout). */
final class ScoutBootstrapper implements Bootstrapper
{
    private ?string $prefix = null;

    public function bootstrap(Tenant $tenant): void
    {
        $this->prefix = (string) config('scout.prefix', '');
        config(['scout.prefix' => $this->prefix.'tenant_'.$tenant->getTenantKey().'_']);
        $this->forgetEngines();
    }

    public function revert(): void
    {
        if ($this->prefix !== null) {
            config(['scout.prefix' => $this->prefix]);
            $this->forgetEngines();
        }

        $this->prefix = null;
    }

    private function forgetEngines(): void
    {
        $manager = 'Laravel\Scout\EngineManager';

        if (class_exists($manager) && app()->resolved($manager)) {
            app($manager)->forgetDrivers();
        }
    }
}
