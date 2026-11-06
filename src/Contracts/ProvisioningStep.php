<?php

declare(strict_types=1);

namespace Atannex\Tenancy\Contracts;

/** One idempotent step of setting up a tenant. */
interface ProvisioningStep
{
    /** @param array<string, mixed> $options */
    public function handle(Tenant $tenant, array $options): void;

    /** Undo handle() when a later step fails. @param array<string, mixed> $options */
    public function rollback(Tenant $tenant, array $options): void;
}
