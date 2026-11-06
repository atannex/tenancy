<?php

declare(strict_types=1);

namespace Atannex\Tenancy\Support;

use Atannex\Tenancy\Contracts\Tenant;
use Atannex\Tenancy\Plans\Plans;
use Atannex\Tenancy\Tenancy;
use Closure;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Rate limiters keyed by tenant, so one tenant can never use up another's
 * budget. Use the name with the throttle middleware: throttle:{name}.
 */
final class TenantRateLimit
{
    /**
     * @param  Closure(Tenant, Request): (Limit|list<Limit>|null)  $limits
     * @param  bool  $perUser  also split each tenant's budget per user (or IP)
     */
    public static function for(string $name, Closure $limits, bool $perUser = false): void
    {
        RateLimiter::for($name, function (Request $request) use ($limits, $perUser) {
            $tenant = app(Tenancy::class)->current();

            if ($tenant === null) {
                return Limit::none();
            }

            $result = $limits($tenant, $request) ?? Limit::none();
            $suffix = $perUser ? ':'.($request->user()?->getAuthIdentifier() ?? $request->ip()) : '';

            return array_map(
                fn (Limit $limit) => $limit->by('tenant:'.$tenant->getTenantKey().':'.$limit->key.$suffix),
                is_array($result) ? $result : [$result],
            );
        });
    }

    /** Requests per minute from the tenant's plan limit $limitKey (unlimited when the plan sets none). */
    public static function perPlan(string $name, string $limitKey, int $fallback = 60, bool $perUser = false): void
    {
        self::for($name, function (Tenant $tenant) use ($limitKey, $fallback) {
            $limit = class_exists(Plans::class) ? app(Plans::class)->limit($tenant, $limitKey) : $fallback;

            return $limit === null ? Limit::none() : Limit::perMinute($limit);
        }, $perUser);
    }
}
