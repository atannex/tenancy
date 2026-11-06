<?php

declare(strict_types=1);

namespace Atannex\Tenancy\Contracts;

/** Points one subsystem (cache, disks, queue …) at a tenant, and back. */
interface Bootstrapper
{
    public function bootstrap(Tenant $tenant): void;

    /** Undo bootstrap(); called in reverse order when the tenant is left. */
    public function revert(): void;
}
