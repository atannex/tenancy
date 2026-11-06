<?php

declare(strict_types=1);

namespace Atannex\Tenancy;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\ServiceProvider;
use Laravel\Octane\Events\RequestReceived;
use Laravel\Octane\Events\TaskReceived;
use Laravel\Octane\Events\TickReceived;

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
        $this->app->singleton(Testing\TenancyFake::class);
        $this->app->bind(Contracts\DnsResolver::class, fn () => $this->app->make(config('tenancy.domains.dns_resolver')));
        $this->app->bind(Contracts\CertificateIssuer::class, fn () => $this->app->make(config('tenancy.domains.certificate_issuer')));
    }

    public function boot(): void
    {
        $config = $this->app['config'];

        if (! $config->get('tenancy.database.central_connection')) {
            $config->set('tenancy.database.central_connection', $config->get('database.default'));
        }

        self::pinInfrastructure($config);

        if ($config->get('tenancy.database.load_migrations')) {
            // Published copies (tenancy:install) win, so nothing runs twice.
            $this->callAfterResolving('migrator', function (Migrator $migrator): void {
                foreach (self::packageMigrations(database_path('migrations')) as $file) {
                    $migrator->path($file);
                }
            });
        }

        $this->publishes([__DIR__.'/../config/tenancy.php' => config_path('tenancy.php')], 'tenancy-config');
        $this->publishesMigrations([__DIR__.'/../database/migrations' => database_path('migrations')], 'tenancy-migrations');
        $this->publishesMigrations([__DIR__.'/../database/sanctum' => database_path('migrations')], 'tenancy-sanctum-migrations');

        $this->registerMiddleware();

        Database\BlueprintMacros::register();
        Database\TenantRules::register();

        $events = $this->app['events'];
        $events->listen(Events\TenancyInitialized::class, fn (Events\TenancyInitialized $e) => $this->app->make(Identification\PathDefaults::class)->initialized($e->tenant));
        $events->listen(Events\TenancyEnded::class, fn () => $this->app->make(Identification\PathDefaults::class)->ended());

        Integrations\Livewire\LivewireTenancy::register($config);
        Integrations\Sanctum\SanctumTenancy::register($config);

        // Jobs carry their tenant, and tenancy is reset around every job.
        $this->app->make(Queue\QueueTenancy::class)->register();

        Schedule::macro('forEachTenant', function (string $command, bool $queue = true) {
            /** @var Schedule $this */
            return $this->command('tenants:run', array_filter([$command, '--queue' => $queue]));
        });

        $routesCached = $this->app->routesAreCached();

        if ($config->get('tenancy.api.enabled') && ! $routesCached) {
            Http\ApiRoutes::register($this->app['router']);
        }

        if ($config->get('tenancy.domains.tls_ask.enabled') && ! $routesCached) {
            $this->app['router']->get($config->get('tenancy.domains.tls_ask.path'), Http\Controllers\TlsAskController::class)
                ->middleware((array) $config->get('tenancy.domains.tls_ask.middleware', []))->name('tenancy.tls.ask');
        }

        if ($config->get('tenancy.lifecycle.schedule', true)) {
            $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
                $schedule->command('tenants:purge --due')->daily()->name('tenancy:purge-due');
                $schedule->command('tenants:domains:verify')->hourly()->name('tenancy:verify-domains');

                if ($this->app->getProviders(TenancyModulesServiceProvider::class) !== [] && config('tenancy.audit_log.enabled', true)) {
                    $schedule->command('tenancy:prune-audit')->daily()->name('tenancy:prune-audit');
                }
            });
        }

        if ($config->get('tenancy.reset_between_requests', true)) {
            $reset = fn () => $this->app->make(Tenancy::class)->reset();

            // Reset last: callbacks added during the request (dispatchAfterResponse
            // jobs, deferred work) still run inside the tenant. terminate() walks
            // the list by index, so a callback appended now runs after them.
            // An app that serves several requests (tests) keeps old callbacks, so
            // only the reset appended in this terminate() runs.
            $cycle = 0;
            $this->app->terminating(function () use (&$cycle, $reset): void {
                $current = ++$cycle;
                $this->app->terminating(function () use (&$cycle, $current, $reset): void {
                    $current === $cycle && $reset();
                });
            });

            foreach ([RequestReceived::class, TaskReceived::class, TickReceived::class] as $event) {
                if (class_exists($event)) {
                    $events->listen($event, $reset);
                }
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
                Console\SuspendCommand::class,
                Console\ReactivateCommand::class,
                Console\ArchiveCommand::class,
                Console\RestoreCommand::class,
                Console\PurgeCommand::class,
                Console\RetryCommand::class,
                Console\DownCommand::class,
                Console\UpCommand::class,
                Console\ExportCommand::class,
                Console\ImportCommand::class,
                Console\VerifyDomainsCommand::class,
                Console\PromoteCommand::class,
            ]);
        }
    }

    /**
     * Database-backed queues, caches, sessions and batches with no connection
     * of their own follow the default connection, which a dedicated tenant
     * switches. Pin them to the central connection so jobs, cache entries and
     * sessions never land in (or get read from) a tenant's database.
     */
    public static function pinInfrastructure(Repository $config): void
    {
        if (! $config->get('tenancy.database.pin_infrastructure', true)) {
            return;
        }

        $central = $config->get('tenancy.database.central_connection') ?: $config->get('database.default');

        foreach ((array) $config->get('queue.connections', []) as $name => $queue) {
            if (is_array($queue) && ($queue['driver'] ?? null) === 'database' && empty($queue['connection'])) {
                $config->set("queue.connections.{$name}.connection", $central);
            }
        }

        foreach (['queue.batching', 'queue.failed'] as $key) {
            if (is_array($config->get($key)) && empty($config->get("{$key}.database"))) {
                $config->set("{$key}.database", $central);
            }
        }

        foreach ((array) $config->get('cache.stores', []) as $name => $store) {
            if (! is_array($store) || ($store['driver'] ?? null) !== 'database') {
                continue;
            }

            foreach (['connection', 'lock_connection'] as $key) {
                if (empty($store[$key])) {
                    $config->set("cache.stores.{$name}.{$key}", $central);
                }
            }
        }

        if ($config->get('session.driver') === 'database' && empty($config->get('session.connection'))) {
            $config->set('session.connection', $central);
        }
    }

    /**
     * The package migrations, minus any the app has published (matched by name
     * without the timestamp, since publishing renames them).
     *
     * @return list<string>
     */
    public static function packageMigrations(string $appFolder): array
    {
        $strip = fn (string $file) => preg_replace('/^\d{4}_\d{2}_\d{2}_\d{6}_/', '', basename($file));
        $published = array_map($strip, glob($appFolder.'/*.php') ?: []);

        return array_values(array_filter(
            glob(__DIR__.'/../database/migrations/*.php') ?: [],
            fn (string $file) => ! in_array($strip($file), $published, true),
        ));
    }

    private function registerMiddleware(): void
    {
        $router = $this->app['router'];
        $router->aliasMiddleware('tenant', Http\Middleware\InitializeTenancy::class);
        $router->aliasMiddleware('tenant.optional', Http\Middleware\InitializeTenancyIfPossible::class);
        $router->aliasMiddleware('tenant.active', Http\Middleware\EnsureTenantActive::class);
        $router->aliasMiddleware('central', Http\Middleware\EnsureCentral::class);
        $router->aliasMiddleware('tenant.session', Http\Middleware\InitializeTenancyAfterSession::class);
        $router->aliasMiddleware('tenant.token', Http\Middleware\EnsureTokenTenant::class);

        // Tenancy must be initialized before route model binding runs.
        $kernel = $this->app->make(Kernel::class);

        if (method_exists($kernel, 'prependToMiddlewarePriority')) {
            $kernel->prependToMiddlewarePriority(Http\Middleware\InitializeTenancy::class);
            $kernel->prependToMiddlewarePriority(Http\Middleware\InitializeTenancyIfPossible::class);
            // After the session and auth, still before route model binding.
            $kernel->addToMiddlewarePriorityBefore(SubstituteBindings::class, Http\Middleware\InitializeTenancyAfterSession::class);
        }
    }
}
