<?php

declare(strict_types=1);

namespace Atannex\Tenancy;

use Atannex\Billing\Events\PaymentSucceeded;
use Atannex\Tenancy\Audit\AuditSubscriber;
use Atannex\Tenancy\Events\TenancyEnded;
use Atannex\Tenancy\Events\TenancyInitialized;
use Atannex\Tenancy\Identification\SessionResolver;
use Atannex\Tenancy\Identification\UserResolver;
use Atannex\Tenancy\Integrations\Billing\ApplyPaidPlan;
use Atannex\Tenancy\Models\Membership;
use Atannex\Tenancy\People\Impersonations;
use Atannex\Tenancy\People\Members;
use Atannex\Tenancy\People\RoleCache;
use Atannex\Tenancy\People\Roles;
use Atannex\Tenancy\Plans\Features;
use Atannex\Tenancy\Plans\Quotas;
use Illuminate\Auth\Events\Authenticated;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

/** Plans, features, quotas, metering, settings, people and the audit log. */
final class TenancyModulesServiceProvider extends ServiceProvider
{
    private const array SECTIONS = ['plans', 'quotas', 'metering', 'settings', 'people', 'audit_log', 'billing'];

    public function register(): void
    {
        $defaults = require __DIR__.'/../config/tenancy-modules.php';
        $config = $this->app['config'];

        foreach (self::SECTIONS as $section) {
            // Defaults, then a published config/tenancy-modules.php, then tenancy.{section} set anywhere else.
            $merged = self::merge($defaults[$section], (array) $config->get("tenancy-modules.{$section}", []));
            $config->set("tenancy.{$section}", self::merge($merged, (array) $config->get("tenancy.{$section}", [])));
        }

        $drivers = (array) $config->get('tenancy.identification.drivers', []);
        $config->set('tenancy.identification.drivers', $drivers + ['session' => SessionResolver::class, 'user' => UserResolver::class]);

        foreach ([Plans\Plans::class, Features::class, Quotas::class, Plans\Metering::class, Plans\Settings::class, Roles::class, Members::class, People\Invitations::class, Impersonations::class, People\Routes::class, RoleCache::class, Audit\AuditRecorder::class] as $service) {
            $this->app->singleton($service);
        }

        // Seats are counted live from active memberships.
        $this->app->afterResolving(Quotas::class, function (Quotas $quotas): void {
            if ($key = config('tenancy.people.seat_quota')) {
                $quotas->countUsing((string) $key, fn ($tenant) => $this->app->make(Members::class)->activeCount($tenant));
            }
        });
    }

    public function boot(): void
    {
        $this->app['router']->aliasMiddleware('tenant.feature', Http\Middleware\EnsureFeature::class);
        $this->app['router']->aliasMiddleware('tenant.member', Http\Middleware\EnsureMember::class);

        $this->callAfterResolving('blade.compiler', function ($blade): void {
            $blade->if('tenantFeature', fn (string $feature) => $this->app->make(Features::class)->active($feature));
        });

        if (config('tenancy.people.gate', true) && $this->app->bound(Gate::class)) {
            $this->app->make(Gate::class)->before(function ($user, string $ability) {
                // Only tenant abilities: a tenant owner must never pass central checks (Horizon, admin …).
                if (! Str::is((array) config('tenancy.people.gate_abilities', []), $ability)) {
                    return null;
                }

                $tenant = $this->app->make(Tenancy::class)->current();

                if ($tenant === null || ! is_object($user) || ! method_exists($user, 'tenantRole') || ! ($role = $this->app->make(RoleCache::class)->role($user, $tenant))) {
                    return null;
                }

                return $this->app->make(Roles::class)->allows($role, $ability) ? true : null;
            });
        }

        $flushRoles = fn () => $this->app->make(RoleCache::class)->flush();
        $this->app['events']->listen([TenancyInitialized::class, TenancyEnded::class], $flushRoles);
        Membership::saved($flushRoles);
        Membership::deleted($flushRoles);

        // An impersonation login ends with its TTL, and never follows the session into another tenant.
        $this->app['events']->listen(Authenticated::class, fn () => $this->app->make(Impersonations::class)->enforce());
        $this->app['events']->listen(TenancyInitialized::class, fn (TenancyInitialized $event) => $this->app->make(Impersonations::class)->enforce($event->tenant));

        $this->app['events']->listen('Atannex\\Tenancy\\Events\\*', fn (string $name, array $payload) => $this->app->make(AuditSubscriber::class)->handle($name, $payload));

        if (class_exists(PaymentSucceeded::class) && config('tenancy.billing.enabled', true)) {
            $this->app['events']->listen(PaymentSucceeded::class, [ApplyPaidPlan::class, 'handle']);
        }

        $this->app->booted(fn () => $this->app->make(People\Routes::class)->register());

        if ($this->app->runningInConsole()) {
            $this->commands([Console\PruneAuditCommand::class]);
            $this->publishes([__DIR__.'/../config/tenancy-modules.php' => $this->app->configPath('tenancy-modules.php')], ['tenancy-config', 'tenancy-modules-config']);
        }
    }

    /**
     * Published settings over the defaults, key by key at every depth. Lists
     * (role abilities, gate_abilities …) are replaced whole, never merged.
     *
     * @param  array<array-key, mixed>  $defaults
     * @param  array<array-key, mixed>  $overrides
     * @return array<array-key, mixed>
     */
    private static function merge(array $defaults, array $overrides): array
    {
        foreach ($overrides as $key => $value) {
            $defaults[$key] = is_array($value) && ! array_is_list($value) && is_array($defaults[$key] ?? null) && ! array_is_list($defaults[$key])
                ? self::merge($defaults[$key], $value)
                : $value;
        }

        return $defaults;
    }
}
