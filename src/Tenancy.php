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
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use Throwable;

final class Tenancy
{
    /** @var list<Bootstrapper>|null */
    private ?array $bootstrappers = null;

    /** @var list<Bootstrapper> bootstrappers currently applied, in order */
    private array $applied = [];

    public function __construct(
        private readonly Container $app,
        private readonly TenantContext $context,
        private readonly Repository $config,
    ) {}

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
        return (bool) $this->config->get('tenancy.strict', true);
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
        $this->transition($left, $this->context->current());
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

        try {
            return $callback($tenant);
        } finally {
            $this->unwindTo($depth);
        }
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

        try {
            return $callback();
        } finally {
            $this->unwindTo($depth);
        }
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

    /** End every frame and revert every bootstrapper. */
    public function reset(): void
    {
        $this->unwindTo(0);
    }

    public function find(string $keyOrSlug): ?Tenant
    {
        if ($keyOrSlug === '') {
            return null;
        }

        /** @var (Model&Tenant)|null */
        return $this->query()
            ->where(fn (Builder $q) => $q->whereKey($keyOrSlug)->orWhere('slug', strtolower($keyOrSlug)))
            ->first();
    }

    public function findOrFail(string $keyOrSlug): Tenant
    {
        return $this->find($keyOrSlug) ?? throw TenantNotFound::forKey($keyOrSlug);
    }

    /** @return Builder<Model> */
    public function query(): Builder
    {
        return $this->newModel()->newQuery();
    }

    /** @return class-string<Model&Tenant> */
    public function model(): string
    {
        return $this->config->get('tenancy.models.tenant');
    }

    public function centralConnection(): string
    {
        return (string) ($this->config->get('tenancy.database.central_connection') ?: $this->config->get('database.default'));
    }

    /** @return list<Bootstrapper> */
    public function bootstrappers(): array
    {
        return $this->bootstrappers ??= array_map(function (string $class): Bootstrapper {
            $bootstrapper = $this->app->make($class);

            if (! $bootstrapper instanceof Bootstrapper) {
                throw new InvalidArgumentException("[{$class}] must implement ".Bootstrapper::class.'.');
            }

            return $bootstrapper;
        }, array_values((array) $this->config->get('tenancy.bootstrappers', [])));
    }

    private function newModel(): Model
    {
        $class = $this->model();
        $model = new $class;

        if (! $model instanceof Tenant) {
            throw new InvalidArgumentException("[{$class}] must implement ".Tenant::class.'.');
        }

        return $model;
    }

    private function enter(?Tenant $tenant): void
    {
        $from = $this->context->current();

        try {
            $this->transition($from, $tenant);
        } catch (Throwable $e) {
            $this->revertApplied();

            if ($from !== null) {
                $this->transition(null, $from);
            }

            throw $e;
        }

        $this->context->push($tenant);
    }

    private function unwindTo(int $depth): void
    {
        while ($this->context->depth() > $depth) {
            $this->end();
        }
    }

    private function transition(?Tenant $from, ?Tenant $to): void
    {
        if ($from?->getTenantKey() === $to?->getTenantKey()) {
            return;
        }

        if ($from !== null) {
            $this->app->make('events')->dispatch(new TenancyEnding($from));
            $this->revertApplied();
            $this->app->make('events')->dispatch(new TenancyEnded($from));
        }

        if ($to !== null) {
            $this->app->make('events')->dispatch(new TenancyInitializing($to));

            foreach ($this->bootstrappers() as $bootstrapper) {
                $bootstrapper->bootstrap($to);
                $this->applied[] = $bootstrapper;
            }

            $this->app->make('events')->dispatch(new TenancyInitialized($to));
        }
    }

    private function revertApplied(): void
    {
        foreach (array_reverse($this->applied) as $bootstrapper) {
            $bootstrapper->revert();
        }

        $this->applied = [];
    }
}
