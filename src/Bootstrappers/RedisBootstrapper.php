<?php

declare(strict_types=1);

namespace Atannex\Tenancy\Bootstrappers;

use Atannex\Tenancy\Contracts\Bootstrapper;
use Atannex\Tenancy\Contracts\Tenant;
use Illuminate\Support\Facades\Redis;
use LogicException;

/**
 * Prefixes direct Redis use (Redis::get()) per tenant. Leave the queue and
 * cache connections out of tenancy.redis.connections: queues must stay
 * central, and the cache has its own bootstrapper. phpredis only.
 */
final class RedisBootstrapper implements Bootstrapper
{
    /** @var array<string, string> */
    private array $originals = [];

    /** The key prefix one tenant's keys get, after the connection's own. */
    public static function prefix(Tenant $tenant): string
    {
        return 'tenant_'.$tenant->getTenantKey().':';
    }

    public function bootstrap(Tenant $tenant): void
    {
        foreach ((array) config('tenancy.redis.connections', []) as $name) {
            $client = Redis::connection($name)->client();

            if (! $client instanceof \Redis) {
                throw new LogicException("Redis connection [{$name}] is not phpredis; tenant prefixes need the phpredis extension.");
            }

            $original = (string) $client->getOption(\Redis::OPT_PREFIX);
            $this->originals[$name] = $original;
            $client->setOption(\Redis::OPT_PREFIX, $original.self::prefix($tenant));
        }
    }

    public function revert(): void
    {
        foreach ($this->originals as $name => $prefix) {
            Redis::connection($name)->client()->setOption(\Redis::OPT_PREFIX, $prefix);
        }

        $this->originals = [];
    }
}
