<?php

declare(strict_types=1);

namespace Atannex\Tenancy\Integrations\Billing;

use Atannex\Tenancy\Plans\Plans;
use Atannex\Tenancy\Tenancy;

/**
 * Listens to atannex/billing's PaymentSucceeded: a payment whose metadata
 * names a tenant and a plan moves that tenant to the plan. Registered only
 * when atannex/billing is installed.
 */
final class ApplyPaidPlan
{
    public function __construct(private readonly Tenancy $tenancy, private readonly Plans $plans) {}

    /** @param object{payment: object{metadata: array<string, mixed>|null}} $event */
    public function handle(object $event): void
    {
        $metadata = (array) ($event->payment->metadata ?? []);
        $tenantKey = $metadata[config('tenancy.billing.metadata.tenant', 'tenant')] ?? null;
        $plan = $metadata[config('tenancy.billing.metadata.plan', 'plan')] ?? null;

        if (! is_string($tenantKey) || ! is_string($plan) || $tenantKey === '' || $plan === '') {
            return;
        }

        $tenant = $this->tenancy->findByKey($tenantKey);

        if ($tenant !== null) {
            $this->plans->change($tenant, $plan);
        }
    }
}
