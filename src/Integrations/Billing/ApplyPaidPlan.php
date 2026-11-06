<?php

declare(strict_types=1);

namespace Atannex\Tenancy\Integrations\Billing;

use Atannex\Tenancy\Contracts\Tenant;
use Atannex\Tenancy\Enums\MembershipStatus;
use Atannex\Tenancy\Models\Membership;
use Atannex\Tenancy\People\Users;
use Atannex\Tenancy\Plans\Plans;
use Atannex\Tenancy\Tenancy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Listens to atannex/billing's PaymentSucceeded: a payment whose metadata
 * names a tenant and a plan moves that tenant to the plan. Registered only
 * when atannex/billing is installed.
 *
 * The metadata is trusted, so your server must set it when it starts the
 * payment. tenancy.billing.prices makes sure the payment covers the plan,
 * and tenancy.billing.require_member that the payer belongs to the tenant.
 * A payment that fails a check is logged and changes nothing.
 */
final class ApplyPaidPlan
{
    public function __construct(private readonly Tenancy $tenancy, private readonly Plans $plans) {}

    /** @param object{payment: object} $event */
    public function handle(object $event): void
    {
        $payment = $event->payment;
        $metadata = (array) ($payment->metadata ?? []);
        $tenantKey = $metadata[config('tenancy.billing.metadata.tenant', 'tenant')] ?? null;
        $plan = $metadata[config('tenancy.billing.metadata.plan', 'plan')] ?? null;

        if (! is_string($tenantKey) || ! is_string($plan) || $tenantKey === '' || $plan === '') {
            return;
        }

        $tenant = $this->tenancy->findByKey($tenantKey);

        $refusal = match (true) {
            ! $tenant instanceof Model => 'the tenant does not exist',
            $this->plans->find($plan) === null => "the plan [{$plan}] does not exist",
            ! $this->covers($payment, $plan) => "the payment does not cover the plan [{$plan}]",
            ! $this->payerBelongs($payment, $tenant) => 'the payer is not a member of the tenant',
            default => null,
        };

        if ($refusal !== null) {
            Log::warning("Tenancy did not apply a paid plan: {$refusal}.", ['tenant' => $tenantKey, 'plan' => $plan, 'payment' => $payment->reference ?? $payment->id ?? null]);

            return;
        }

        $this->plans->change($tenant, $plan);
    }

    private function covers(object $payment, string $plan): bool
    {
        $price = config("tenancy.billing.prices.{$plan}");

        if (! is_array($price)) {
            return true;
        }

        $amount = (int) ($payment->amount ?? 0) - (int) ($payment->amount_refunded ?? 0);
        $currency = $price['currency'] ?? null;

        return $amount >= (int) ($price['amount'] ?? 0)
            && ($currency === null || Str::upper((string) $currency) === Str::upper((string) ($payment->currency ?? '')));
    }

    private function payerBelongs(object $payment, ?Tenant $tenant): bool
    {
        if (! config('tenancy.billing.require_member', false)) {
            return true;
        }

        $email = Str::lower(trim((string) ($payment->customer_email ?? '')));
        if ($tenant === null || $email === '') {
            return false;
        }

        $user = Users::model()::query()->whereRaw('lower(email) = ?', [$email])->first();

        return $user !== null && Membership::query()
            ->where('tenant_id', $tenant->getTenantKey())
            ->where('user_id', Users::key($user))
            ->where('status', MembershipStatus::Active->value)
            ->exists();
    }
}
