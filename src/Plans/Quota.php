<?php

declare(strict_types=1);

namespace Atannex\Tenancy\Plans;

use Atannex\Tenancy\Contracts\Tenant;
use Atannex\Tenancy\Exceptions\QuotaExceeded;
use Closure;

final class Quota
{
    /** @param (Closure(Tenant): int)|null $counter */
    public function __construct(
        public readonly Tenant $tenant,
        public readonly string $key,
        private readonly Plans $plans,
        private readonly Metering $metering,
        private readonly ?Closure $counter,
    ) {}

    /** Null = unlimited. */
    public function limit(): ?int
    {
        return $this->plans->limit($this->tenant, $this->key);
    }

    public function used(): int
    {
        return $this->counter !== null
            ? (int) ($this->counter)($this->tenant)
            : $this->metering->meter($this->key, $this->tenant)->value();
    }

    public function remaining(): ?int
    {
        $limit = $this->limit();

        return $limit === null ? null : max(0, $limit - $this->used());
    }

    public function allows(int $amount = 1): bool
    {
        $limit = $this->limit();

        return $limit === null || $this->used() + $amount <= $limit;
    }

    public function assert(int $amount = 1): void
    {
        if (! $this->allows($amount)) {
            throw QuotaExceeded::for($this->key, $this->limit());
        }
    }

    /**
     * Use up some of the quota. Counted quotas are only checked; metered
     * quotas are recorded first and rolled back if that went over, so two
     * concurrent calls cannot both squeeze past the limit.
     */
    public function consume(int $amount = 1): void
    {
        if ($this->counter !== null) {
            $this->assert($amount);

            return;
        }

        $meter = $this->metering->meter($this->key, $this->tenant);
        $limit = $this->limit();
        $value = $meter->record($amount);

        if ($limit !== null && $value > $limit) {
            $meter->subtract($amount);

            throw QuotaExceeded::for($this->key, $limit);
        }
    }
}
