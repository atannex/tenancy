<?php

declare(strict_types=1);

namespace Atannex\Tenancy\Queue;

use Atannex\Tenancy\Attributes\Central;
use Atannex\Tenancy\Exceptions\TenantNotFound;
use Atannex\Tenancy\Http\AccessGuard;
use Atannex\Tenancy\Lifecycle\Maintenance;
use Atannex\Tenancy\Tenancy;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Queue;
use ReflectionClass;

/**
 * Jobs dispatched inside a tenant carry its key and run inside it again on
 * the worker; tenancy is reset around every job so nothing leaks to the
 * next one. Sync jobs run inline in the caller's context and are left alone.
 */
final class QueueTenancy
{
    public const string PAYLOAD_KEY = 'tenant_id';

    public function __construct(private readonly Tenancy $tenancy) {}

    public function register(): void
    {
        // Static on the queue: resolve at call time so a stale instance is never used.
        Queue::createPayloadUsing(fn ($connection, $queue, array $payload) => app(self::class)->payload($payload));

        $events = app('events');
        $events->listen(JobProcessing::class, fn (JobProcessing $e) => $this->processing($e));
        $events->listen([JobProcessed::class, JobFailed::class, JobExceptionOccurred::class], function ($e): void {
            if (! $this->isSync($e->connectionName)) {
                $this->tenancy->reset();
            }
        });
    }

    /** @return array<string, string> */
    public function payload(array $payload): array
    {
        $tenant = $this->tenancy->current();
        // At hook time commandName may still hold the job object itself.
        $class = $payload['data']['commandName'] ?? null;
        $class = is_object($class) ? $class::class : $class;

        if ($tenant === null || (is_string($class) && class_exists($class) && (new ReflectionClass($class))->getAttributes(Central::class) !== [])) {
            return [];
        }

        return [self::PAYLOAD_KEY => $tenant->getTenantKey()];
    }

    private function processing(JobProcessing $event): void
    {
        if ($this->isSync($event->connectionName)) {
            return;
        }

        $this->tenancy->reset();
        $key = $event->job->payload()[self::PAYLOAD_KEY] ?? null;

        if (! is_string($key) || $key === '') {
            return;
        }

        $tenant = $this->tenancy->findByKey($key) ?? throw TenantNotFound::forKey($key);

        // Data is moving (tenants:promote): put the job back for later, untouched.
        if (app(Maintenance::class)->holdsJobs($tenant)) {
            $event->job->release((int) config('tenancy.queue.hold_delay', 30));
            $event->job->delete();

            return;
        }

        if (! config('tenancy.queue.run_inactive', false)) {
            AccessGuard::assertAccessible($tenant);
        }

        $this->tenancy->initialize($tenant);
    }

    private function isSync(string $connection): bool
    {
        return config("queue.connections.{$connection}.driver") === 'sync';
    }
}
