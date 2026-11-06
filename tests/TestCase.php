<?php

declare(strict_types=1);

namespace Atannex\Tenancy\Tests;

use Atannex\Tenancy\Facades\Tenancy;
use Atannex\Tenancy\TenancyModulesServiceProvider;
use Atannex\Tenancy\TenancyServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    use RefreshDatabase;

    protected function getPackageProviders($app): array
    {
        return [TenancyServiceProvider::class, TenancyModulesServiceProvider::class];
    }

    protected function getPackageAliases($app): array
    {
        return ['Tenancy' => Tenancy::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('t', 32)));
        $app['config']->set('cache.default', 'array');
        $app['config']->set('tenancy.identification.central_domains', ['central.test']);
        $app['config']->set('tenancy.audit.paths', [__DIR__.'/Fixtures/Models']);
        $app['config']->set('tenancy.models.tenant', Fixtures\Models\PlanTenant::class);
        $app['config']->set('auth.providers.users.model', Fixtures\Models\User::class);
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/Fixtures/migrations');
    }
}
