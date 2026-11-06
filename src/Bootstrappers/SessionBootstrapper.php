<?php

declare(strict_types=1);

namespace Atannex\Tenancy\Bootstrappers;

use Atannex\Tenancy\Contracts\Bootstrapper;
use Atannex\Tenancy\Contracts\Tenant;

/** A session cookie per tenant, so sessions never cross tenants on a shared host. */
final class SessionBootstrapper implements Bootstrapper
{
    private ?string $cookie = null;

    public function bootstrap(Tenant $tenant): void
    {
        $this->cookie = (string) config('session.cookie');
        config(['session.cookie' => $this->cookie.'_'.substr(hash('sha256', $tenant->getTenantKey()), 0, 12)]);
        $this->forgetDrivers();
    }

    public function revert(): void
    {
        if ($this->cookie !== null) {
            config(['session.cookie' => $this->cookie]);
            $this->forgetDrivers();
        }

        $this->cookie = null;
    }

    private function forgetDrivers(): void
    {
        if (app()->resolved('session')) {
            app('session')->forgetDrivers();
        }
    }
}
