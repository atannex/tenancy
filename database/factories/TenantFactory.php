<?php

declare(strict_types=1);

namespace Atannex\Tenancy\Database\Factories;

use Atannex\Tenancy\Enums\DomainType;
use Atannex\Tenancy\Enums\TenantStatus;
use Atannex\Tenancy\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Tenant> */
class TenantFactory extends Factory
{
    protected $model = Tenant::class;

    public function definition(): array
    {
        $word = Str::lower(Str::random(10));

        return ['name' => 'Tenant '.$word, 'status' => TenantStatus::Active];
    }

    public function withDomain(string $host, DomainType $type = DomainType::Primary): static
    {
        return $this->afterCreating(fn (Tenant $tenant) => $tenant->domains()->create(['host' => $host, 'type' => $type, 'verified_at' => now()]));
    }

    public function suspended(): static
    {
        return $this->state(['status' => TenantStatus::Suspended, 'suspended_at' => now()]);
    }

    public function status(TenantStatus $status): static
    {
        return $this->state(['status' => $status]);
    }
}
