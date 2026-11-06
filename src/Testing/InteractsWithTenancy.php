<?php

declare(strict_types=1);

namespace Atannex\Tenancy\Testing;

use Atannex\Tenancy\Contracts\Tenant;
use Atannex\Tenancy\Tenancy;
use Closure;
use Illuminate\Database\Eloquent\Model;
use PHPUnit\Framework\Assert;

/** For app test cases: use in a Laravel TestCase. */
trait InteractsWithTenancy
{
    /** @param array<string, mixed> $attributes */
    protected function createTenant(array $attributes = []): Tenant
    {
        $model = app(Tenancy::class)->model();

        return $model::factory()->create($attributes);
    }

    protected function actingAsTenant(Tenant|string $tenant): static
    {
        app(Tenancy::class)->initialize($tenant);
        $this->beforeApplicationDestroyed(fn () => app(Tenancy::class)->reset());

        return $this;
    }

    /**
     * Create a row in one tenant and prove another tenant can neither see,
     * update nor delete it.
     *
     * @param  class-string<Model>  $model
     * @param  Closure(): Model  $make  creates one saved row in the current tenant
     */
    protected function assertIsolated(string $model, Closure $make): void
    {
        $tenancy = app(Tenancy::class);
        $owner = $this->createTenant();
        $other = $this->createTenant();

        $row = $tenancy->run($owner, fn () => $make());
        $key = $row->getKey();

        $tenancy->run($other, function () use ($model, $key): void {
            Assert::assertFalse($model::query()->whereKey($key)->exists(), "[{$model}] row of one tenant is visible to another.");
            Assert::assertSame(0, (int) $model::query()->whereKey($key)->delete(), "[{$model}] row of one tenant can be deleted by another.");
        });

        $tenancy->run($owner, function () use ($model, $key): void {
            Assert::assertTrue($model::query()->whereKey($key)->exists(), "[{$model}] row disappeared from its own tenant.");
        });
    }
}
