<?php

declare(strict_types=1);

namespace Atannex\Tenancy;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Support\ServiceProvider;
use Laravel\Octane\Events\RequestReceived;

final class TenancyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/tenancy.php', 'tenancy');

        $this->app->singleton(Context\TenantContext::class);
        $this->app->singleton(Tenancy::class);
        $this->app->singleton(Identification\ResolverCache::class);
        $this->app->singleton(Identification\ResolverPipeline::class);
        $this->app->singleton(Identification\PathDefaults::class);
        $this->app->singleton(Queue\QueueTenancy::class);
        $this->app->singleton(Isolation\TenantDatabase::class);
    }

    public function boot(): void
    {
        $config = $this->app['config'];

        if (! $config->get('tenancy.database.central_connection')) {
            $config->set('tenancy.database.central_connection', $config->get('database.default'));
        }

        if ($config->get('tenancy.database.load_migrations')) {
            $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        }

        $this->publishes([__DIR__.'/../config/tenancy.php' => config_path('tenancy.php')], 'tenancy-config');
        $this->publishesMigrations([__DIR__.'/../database/migrations' => database_path('migrations')], 'tenancy-migrations');

        $this->registerMiddleware();

        Database\BlueprintMacros::register();
        Database\TenantRules::register();

        $events = $this->app['events'];
        $events->listen(Events\TenancyInitialized::class, fn (Events\TenancyInitialized $e) => $this->app->make(Identification\PathDefaults::class)->initialized($e->tenant));
        $events->listen(Events\TenancyEnded::class, fn () => $this->app->make(Identification\PathDefaults::class)->ended());

        // Jobs carry their tenant, and tenancy is reset around every job.
        $this->app->make(Queue\QueueTenancy::class)->register();

        Schedule::macro('forEachTenant', function (string $command, bool $queue = true) {
            /** @var Schedule $this */
            return $this->command('tenants:run', array_filter([$command, '--queue' => $queue]));
        });

        if ($config->get('tenancy.reset_between_requests', true)) {
            $reset = fn () => $this->app->make(Tenancy::class)->reset();

            $this->app->terminating($reset);

            if (class_exists(RequestReceived::class)) {
                $events->listen(RequestReceived::class, $reset);
            }
        }

        if ($this->app->runningInConsole()) {
            $this->commands([
                Console\InstallCommand::class,
                Console\ListTenantsCommand::class,
                Console\CreateTenantCommand::class,
                Console\AuditCommand::class,
                Console\DoctorCommand::class,
                Console\RunCommand::class,
                Console\MigrateCommand::class,
                Console\RollbackCommand::class,
                Console\FreshCommand::class,
                Console\SeedCommand::class,
                Console\StatusCommand::class,
            ]);
        }
    }

    private function registerMiddleware(): void
    {
        $router = $this->app['router'];
        $router->aliasMiddleware('tenant', Http\Middleware\InitializeTenancy::class);
        $router->aliasMiddleware('tenant.optional', Http\Middleware\InitializeTenancyIfPossible::class);
        $router->aliasMiddleware('tenant.active', Http\Middleware\EnsureTenantActive::class);
        $router->aliasMiddleware('central', Http\Middleware\EnsureCentral::class);

        // Tenancy must be initialized before route model binding runs.
        $kernel = $this->app->make(Kernel::class);

        if (method_exists($kernel, 'prependToMiddlewarePriority')) {
            $kernel->prependToMiddlewarePriority(Http\Middleware\InitializeTenancy::class);
            $kernel->prependToMiddlewarePriority(Http\Middleware\InitializeTenancyIfPossible::class);
        }
    }
}
