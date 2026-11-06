<?php

declare(strict_types=1);

namespace Atannex\Tenancy;

use Atannex\Tenancy\Context\TenantContext;
use Atannex\Tenancy\Contracts\Bootstrapper;
use Atannex\Tenancy\Contracts\Tenant;
use Atannex\Tenancy\Enums\TenantStatus;
use Atannex\Tenancy\Events\TenancyEnded;
use Atannex\Tenancy\Events\TenancyEnding;
use Atannex\Tenancy\Events\TenancyInitialized;
use Atannex\Tenancy\Events\TenancyInitializing;
use Atannex\Tenancy\Exceptions\NoTenantContext;
use Atannex\Tenancy\Exceptions\TenantNotFound;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Bus\PendingDispatch;
use InvalidArgumentException;
use Throwable;

/**
 * The tenant context and its bootstrappers. Config and container are read
 * at the moment of use, never captured, so long-lived workers (Octane,
 * queues) always see the current ones.
 */
final class Tenancy
{
    /** @var list<Bootstrapper> bootstrappers currently applied, in order */
    private array $applied = [];

    public function __construct(private readonly TenantContext $context) {}

    public function current(): ?Tenant
    {
        return $this->context->current();
    }

    public function currentOrFail(string $operation = 'This operation'): Tenant
    {
        return $this->current() ?? throw NoTenantContext::forOperation($operation);
    }

    public function initialized(): bool
    {
        return $this->current() !== null;
    }

    public function inCentral(): bool
    {
        return $this->context->inCentral();
    }

    public function strict(): bool
    {
        return (bool) config('tenancy.strict', true);
    }

    public function initialize(Tenant|string $tenant): Tenant
    {
        $tenant = $tenant instanceof Tenant ? $tenant : $this->findOrFail($tenant);

        $this->enter($tenant);

        return $tenant;
    }

    public function end(): void
    {
        if ($this->context->depth() === 0) {
            return;
        }

        $left = $this->context->pop();

        if ($error = $this->transition($left, $this->context->current())) {
            throw $error;
        }
    }

    /**
     * @template T
     *
     * @param  Closure(Tenant): T  $callback
     * @return T
     */
    public function run(Tenant|string $tenant, Closure $callback): mixed
    {
        $depth = $this->context->depth();
        $tenant = $this->initialize($tenant);

        return $this->guarded($depth, fn () => $callback($tenant));
    }

    /**
     * Run with no tenant and tenant scopes deliberately lifted.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public function central(Closure $callback): mixed
    {
        $depth = $this->context->depth();
        $this->enter(null);

        return $this->guarded($depth, $callback);
    }

    /**
     * Run a callback inside each tenant (active ones unless $query says otherwise).
     *
     * @param  Closure(Tenant): mixed  $callback
     * @param  (Closure(Builder): mixed)|null  $query
     */
    public function each(Closure $callback, ?Closure $query = null, int $chunk = 100): int
    {
        $count = 0;

        $this->central(function () use ($callback, $query, $chunk, &$count): void {
            $builder = $this->query();
            $query ? $query($builder) : $builder->where('status', TenantStatus::Active->value);

            $builder->chunkById($chunk, function ($tenants) use ($callback, &$count): void {
                foreach ($tenants as $tenant) {
                    $this->run($tenant, $callback);
                    $count++;
                }
            });
        });

        return $count;
    }

    /**
     * Create and provision a tenant (queued when tenancy.provisioning.queue is on).
     *
     * @param  array<string, mixed>  $attributes  name, slug, isolation, plan, data …
     * @param  array<string, mixed>  $options  domain, verified, owner, seeder, and your own step options
     */
    public function create(array $attributes, array $options = []): Tenant&Model
    {
        return app(Lifecycle\Provisioner::class)->create($attributes, $options);
    }

    /** Swap provisioning for a fake that activates tenants at once and records them. */
    public function fake(): Testing\TenancyFake
    {
        $fake = app(Testing\TenancyFake::class);
        app()->instance(Lifecycle\Provisioner::class, $fake);

        return $fake;
    }

    /** End every frame and revert every bootstrapper. */
    public function reset(): void
    {
        $this->unwindTo(0);
    }

    /**
     * By exact key first, then by slug.
     *
     * @return (Tenant&Model)|null
     */
    public function find(string $keyOrSlug): ?Tenant
    {
        if ($keyOrSlug === '') {
            return null;
        }

        /** @var (Model&Tenant)|null */
        return $this->findByKey($keyOrSlug) ?? $this->query()->where('slug', strtolower($keyOrSlug))->first();
    }

    /** @return (Tenant&Model)|null */
    public function findByKey(string $key): ?Tenant
    {
        /** @var (Model&Tenant)|null */
        return $key === '' ? null : $this->query()->whereKey($key)->first();
    }

    /** @return Tenant&Model */
    public function findOrFail(string $keyOrSlug): Tenant
    {
        return $this->find($keyOrSlug) ?? throw TenantNotFound::forKey($keyOrSlug);
    }

    /** @return Builder<Model&Tenant> */
    public function query(): Builder
    {
        return $this->newModel()->newQuery();
    }

    /** @return class-string<Model&Tenant> */
    public function model(): string
    {
        return config('tenancy.models.tenant');
    }

    public function centralConnection(): string
    {
        return (string) (config('tenancy.database.central_connection') ?: config('database.default'));
    }

    /** @return list<Bootstrapper> */
    public function bootstrappers(): array
    {
        return array_map(function (string $class): Bootstrapper {
            $bootstrapper = app($class);

            if (! $bootstrapper instanceof Bootstrapper) {
                throw new InvalidArgumentException("[{$class}] must implement ".Bootstrapper::class.'.');
            }

            return $bootstrapper;
        }, array_values((array) config('tenancy.bootstrappers', [])));
    }

    /** @return Model&Tenant */
    private function newModel(): Model
    {
        $class = (string) config('tenancy.models.tenant');
        $model = new $class;

        if (! $model instanceof Model || ! $model instanceof Tenant) {
            throw new InvalidArgumentException("[{$class}] must be an Eloquent model implementing ".Tenant::class.'.');
        }

        return $model;
    }

    /** Run $callback, then unwind to $depth without ever masking the callback's own exception. */
    private function guarded(int $depth, Closure $callback): mixed
    {
        try {
            $result = $callback();
        } catch (Throwable $e) {
            try {
                $this->unwindTo($depth);
            } catch (Throwable $unwind) {
                report($unwind);
            }

            throw $e;
        }

        // fn () => Job::dispatch() returns a PendingDispatch that dispatches when
        // destroyed; destroy it here, inside the context, not after leaving it.
        if ($result instanceof PendingDispatch) {
            $result = null;
        }

        $this->unwindTo($depth);

        return $result;
    }

    private function enter(?Tenant $tenant): void
    {
        $from = $this->context->current();

        try {
            $revertError = $this->transition($from, $tenant);
        } catch (Throwable $e) {
            if ($from !== null && $this->applied === []) {
                try {
                    $this->transition(null, $from);
                } catch (Throwable) {
                    // Nothing applied; the original failure is what matters.
                }
            }

            throw $e;
        }

        $this->context->push($tenant);

        if ($revertError !== null) {
            throw $revertError;
        }
    }

    private function unwindTo(int $depth): void
    {
        $error = null;

        while ($this->context->depth() > $depth) {
            try {
                $this->end();
            } catch (Throwable $e) {
                $error ??= $e;
            }
        }

        if ($error !== null) {
            throw $error;
        }
    }

    /**
     * Move the bootstrappers from one tenant to another. Afterwards either
     * $to is fully applied, or nothing is (and the bootstrap failure is
     * thrown). A failure reverting $from is returned, not thrown, so callers
     * can record the new frame first and keep context and bootstrappers in step.
     */
    private function transition(?Tenant $from, ?Tenant $to): ?Throwable
    {
        if ($from?->getTenantKey() === $to?->getTenantKey()) {
            return null;
        }

        $events = app('events');
        $revertError = null;

        if ($from !== null) {
            $events->dispatch(new TenancyEnding($from));
            $revertError = $this->revertApplied();
            $events->dispatch(new TenancyEnded($from));
        }

        if ($to !== null) {
            $events->dispatch(new TenancyInitializing($to));

            try {
                foreach ($this->bootstrappers() as $bootstrapper) {
                    $bootstrapper->bootstrap($to);
                    $this->applied[] = $bootstrapper;
                }
            } catch (Throwable $e) {
                $this->revertApplied();

                throw $e;
            }

            $events->dispatch(new TenancyInitialized($to));
        }

        return $revertError;
    }

    /** Revert everything applied, continuing past failures; returns the first one. */
    private function revertApplied(): ?Throwable
    {
        $error = null;

        foreach (array_reverse($this->applied) as $bootstrapper) {
            try {
                $bootstrapper->revert();
            } catch (Throwable $e) {
                $error ??= $e;
            }
        }

        $this->applied = [];

        return $error;
    }
}
