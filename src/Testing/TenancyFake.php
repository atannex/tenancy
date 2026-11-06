<?php

declare(strict_types=1);

namespace Atannex\Tenancy\Testing;

use Atannex\Tenancy\Contracts\Tenant;
use Atannex\Tenancy\Enums\TenantStatus;
use Atannex\Tenancy\Events\TenantProvisioned;
use Atannex\Tenancy\Lifecycle\Provisioner;
use Closure;
use Illuminate\Database\Eloquent\Model;
use PHPUnit\Framework\Assert;

/** Tenancy::fake(): provisioning marks tenants active at once and records what happened. */
final class TenancyFake extends Provisioner
{
    /** @var list<array{tenant: Tenant, options: array<string, mixed>}> */
    private array $provisioned = [];

    public function provision(Tenant&Model $tenant, ?array $options = null): bool
    {
        $options ??= (array) (((array) ($tenant->getAttribute('data') ?? []))['provisioning'] ?? []);
        $tenant->forceFill(['status' => TenantStatus::Active])->save();
        $this->provisioned[] = ['tenant' => $tenant, 'options' => $options];
        event(new TenantProvisioned($tenant));

        return true;
    }

    /** @param string|(Closure(Tenant, array<string, mixed>): bool) $slug */
    public function assertProvisioned(string|Closure $slug): void
    {
        $match = fn (array $entry) => is_string($slug) ? $entry['tenant']->getTenantSlug() === $slug : $slug($entry['tenant'], $entry['options']);

        Assert::assertNotEmpty(array_filter($this->provisioned, $match), 'The expected tenant was not provisioned.');
    }

    public function assertNothingProvisioned(): void
    {
        Assert::assertSame([], $this->provisioned, 'Tenants were provisioned unexpectedly.');
    }
}
