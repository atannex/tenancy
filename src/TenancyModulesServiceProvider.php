<?php

declare(strict_types=1);

namespace Atannex\Tenancy;

use Atannex\Billing\Events\PaymentSucceeded;
use Atannex\Tenancy\Audit\AuditSubscriber;
use Atannex\Tenancy\Identification\SessionResolver;
use Atannex\Tenancy\Identification\UserResolver;
use Atannex\Tenancy\Integrations\Billing\ApplyPaidPlan;
use Atannex\Tenancy\People\Members;
use Atannex\Tenancy\People\Roles;
use Atannex\Tenancy\Plans\Features;
use Atannex\Tenancy\Plans\Quotas;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Support\ServiceProvider;

/** Plans, features, quotas, metering, settings, people and the audit log. */
final class TenancyModulesServiceProvider extends ServiceProvider
{
    private const array SECTIONS = ['plans', 'quotas', 'metering', 'settings', 'people', 'audit_log', 'billing'];

    public function register(): void
    {
        $defaults = require __DIR__.'/../config/tenancy-modules.php';
        $config = $this->app['config'];

        foreach (self::SECTIONS as $section) {
            $config->set("tenancy.{$section}", array_replace($defaults[$section], (array) $config->get("tenancy.{$section}", [])));
        }

        $drivers = (array) $config->get('tenancy.identification.drivers', []);
        $config->set('tenancy.identification.drivers', $drivers + ['session' => SessionResolver::class, 'user' => UserResolver::class]);

        foreach ([Plans\Plans::class, Features::class, Quotas::class, Plans\Metering::class, Plans\Settings::class, Roles::class, Members::class, People\Invitations::class, People\Impersonations::class, People\Routes::class, Audit\AuditRecorder::class] as $service) {
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
                if (! is_object($user) || ! method_exists($user, 'tenantRole') || ! ($role = $user->tenantRole())) {
                    return null;
                }

                return $this->app->make(Roles::class)->allows($role, $ability) ? true : null;
            });
        }

        $this->app['events']->listen('Atannex\\Tenancy\\Events\\*', fn (string $name, array $payload) => $this->app->make(AuditSubscriber::class)->handle($name, $payload));

        if (class_exists(PaymentSucceeded::class) && config('tenancy.billing.enabled', true)) {
            $this->app['events']->listen(PaymentSucceeded::class, [ApplyPaidPlan::class, 'handle']);
        }

        $this->app->booted(fn () => $this->app->make(People\Routes::class)->register());

        if ($this->app->runningInConsole()) {
            $this->commands([Console\PruneAuditCommand::class]);
        }
    }
}
