<?php

declare(strict_types=1);

namespace Atannex\Tenancy\Plans;

use Atannex\Tenancy\Contracts\Tenant;
use Atannex\Tenancy\Events\UsageThresholdReached;
use Atannex\Tenancy\Tenancy;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/** One metric of one tenant, counted per period in tenant_usage. */
final class Meter
{
    public function __construct(
        public readonly Tenant $tenant,
        public readonly string $metric,
        private readonly Plans $plans,
    ) {}

    public function period(): string
    {
        return $this->plans->period($this->tenant, $this->metric);
    }

    public function periodKey(): string
    {
        return match ($this->period()) {
            'day' => now()->format('Y-m-d'),
            'year' => now()->format('Y'),
            'all' => 'all',
            default => now()->format('Y-m'),
        };
    }

    /** Add to the current period atomically; returns the new value. */
    public function record(int $amount = 1): int
    {
        $period = $this->periodKey();
        $now = now();

        $this->table()->insertOrIgnore([
            'tenant_id' => $this->tenant->getTenantKey(), 'metric' => $this->metric, 'period' => $period,
            'value' => 0, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $this->row($period)->increment('value', $amount, ['updated_at' => $now]);

        $value = $this->value($period);

        if ($amount > 0) {
            $this->announceThresholds($value - $amount, $value);
        }

        return $value;
    }

    public function subtract(int $amount = 1): int
    {
        $period = $this->periodKey();
        $this->row($period)->decrement('value', $amount, ['updated_at' => now()]);

        return $this->value($period);
    }

    public function value(?string $period = null): int
    {
        return (int) $this->row($period ?? $this->periodKey())->value('value');
    }

    private function announceThresholds(int $before, int $after): void
    {
        $limit = $this->plans->limit($this->tenant, $this->metric);

        if ($limit === null || $limit <= 0) {
            return;
        }

        foreach ((array) config('tenancy.metering.thresholds', []) as $threshold) {
            $mark = (int) $threshold * $limit;

            if ($before * 100 < $mark && $after * 100 >= $mark) {
                event(new UsageThresholdReached($this->tenant, $this->metric, (int) $threshold, $after, $limit));
            }
        }
    }

    private function table(): Builder
    {
        return DB::connection(app(Tenancy::class)->centralConnection())->table('tenant_usage');
    }

    private function row(string $period): Builder
    {
        return $this->table()
            ->where('tenant_id', $this->tenant->getTenantKey())
            ->where('metric', $this->metric)
            ->where('period', $period);
    }
}
