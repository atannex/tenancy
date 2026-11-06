<?php

declare(strict_types=1);

namespace Atannex\Tenancy\Queue;

use Atannex\Tenancy\Attributes\Central;
use Atannex\Tenancy\Exceptions\TenantNotFound;
use Atannex\Tenancy\Http\AccessGuard;
use Atannex\Tenancy\Lifecycle\Maintenance;
use Atannex\Tenancy\Tenancy;
use Illuminate\Broadcasting\BroadcastEvent;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Events\CallQueuedListener;
use Illuminate\Mail\SendQueuedMailable;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Queue\DatabaseQueue;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Queue;
use Illuminate\Queue\RedisQueue;
use Illuminate\Queue\SqsQueue;
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
        if ($tenant === null || $this->isCentral($payload)) {
            return [];
        }

        return [self::PAYLOAD_KEY => $tenant->getTenantKey()];
    }

    /** #[Central] on the job, or on what a wrapper job carries (queued listener, notification, mailable, broadcast). */
    private function isCentral(array $payload): bool
    {
        // At hook time commandName may still hold the job object itself.
        $command = $payload['data']['command'] ?? $payload['data']['commandName'] ?? null;
        $classes = [is_object($command) ? $command::class : $command];

        if (is_object($command)) {
            $classes[] = match (true) {
                $command instanceof CallQueuedListener => $command->class,
                $command instanceof SendQueuedNotifications => $command->notification::class,
                $command instanceof SendQueuedMailable => $command->mailable::class,
                $command instanceof BroadcastEvent => $command->event::class,
                default => null,
            };
        }

        foreach (array_filter($classes, 'is_string') as $class) {
            if (class_exists($class) && (new ReflectionClass($class))->getAttributes(Central::class) !== []) {
                return true;
            }
        }

        return false;
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
            $this->hold($event->job);

            return;
        }

        if (! config('tenancy.queue.run_inactive', false)) {
            AccessGuard::assertAccessible($tenant);
        }

        $this->tenancy->initialize($tenant);
    }

    /**
     * Put a held job back without spending one of its attempts, so a worker
     * with --tries=1 doesn't fail it once the tenant is back. The database,
     * Redis and standard SQS queues get a fresh copy; other drivers (and SQS
     * FIFO, which allows no per-message delay) fall back to release(), which
     * counts the attempt.
     */
    private function hold(Job $job): void
    {
        $delay = max(0, (int) config('tenancy.queue.hold_delay', 30));
        $queue = app('queue')->connection($job->getConnectionName());
        $name = $job->getQueue();
        $payload = $job->getRawBody();

        $requeued = true;

        match (true) {
            // The reserved row counts this attempt already; store the count from before it.
            $queue instanceof DatabaseQueue => (fn () => $this->pushToDatabase($name, $payload, $delay, max(0, $job->attempts() - 1)))->call($queue),
            // The raw body is the payload as pushed, before this attempt was counted.
            $queue instanceof RedisQueue => (fn () => $this->laterRaw($delay, $payload, $name))->call($queue),
            $queue instanceof SqsQueue && ! str_ends_with((string) $name, '.fifo') => $queue->pushRaw($payload, $name, ['DelaySeconds' => min($delay, 900)]),
            default => $requeued = false,
        };

        // release() puts this same job back (Beanstalkd keeps its id), so only a copy allows deleting it.
        $requeued ? $job->delete() : $job->release($delay);
    }

    private function isSync(string $connection): bool
    {
        return config("queue.connections.{$connection}.driver") === 'sync';
    }
}
