<?php

declare(strict_types=1);

namespace Atannex\Tenancy\Lifecycle\Steps;

use Atannex\Tenancy\Contracts\ProvisioningStep;
use Atannex\Tenancy\Contracts\Tenant;
use Atannex\Tenancy\Domains\Domains;
use Atannex\Tenancy\Enums\DomainType;
use Atannex\Tenancy\Identification\Hosts;

/** $options['domain'] becomes the primary domain; $options['verified'] skips DNS verification. */
final class CreatePrimaryDomain implements ProvisioningStep
{
    public function __construct(private readonly Domains $domains) {}

    public function handle(Tenant $tenant, array $options): void
    {
        if (empty($options['domain']) || ! method_exists($tenant, 'domains')) {
            return;
        }

        $host = Hosts::normalize((string) $options['domain']);

        if (! $tenant->domains()->where('host', $host)->exists()) {
            $this->domains->add($tenant, $host, DomainType::Primary, verified: (bool) ($options['verified'] ?? false));
        }
    }

    public function rollback(Tenant $tenant, array $options): void
    {
        if (! empty($options['domain']) && method_exists($tenant, 'domains')) {
            $tenant->domains()->where('host', Hosts::normalize((string) $options['domain']))->delete();
        }
    }
}
