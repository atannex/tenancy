<?php

declare(strict_types=1);

namespace Atannex\Tenancy\Bootstrappers;

use Atannex\Tenancy\Contracts\Bootstrapper;
use Atannex\Tenancy\Contracts\Tenant;
use Illuminate\Support\Facades\URL;

/**
 * Roots url()/route() at the tenant's primary verified domain (or its
 * subdomain of the first central domain), so links in jobs, mail and
 * commands point at the right tenant.
 *
 * During an HTTP request it leaves the root alone by default: the request
 * already arrived on the tenant's host (domain, subdomain) or on purpose on
 * the central one (path, header), and moving links to another host would
 * drop the visitor's session cookie. Link to another tenant from a request
 * with tenant_route() / tenant_url(), or set tenancy.url.requests to true.
 */
final class UrlBootstrapper implements Bootstrapper
{
    /** @var array{0: ?string, 1: ?string}|null the app's own forced root and scheme */
    private ?array $original = null;

    public static function hostFor(Tenant $tenant): ?string
    {
        $host = $tenant->domains()->whereNotNull('verified_at')->orderByRaw("case when type = 'primary' then 0 else 1 end")->oldest()->value('host');

        if ($host) {
            return $host;
        }

        $central = (array) config('tenancy.identification.central_domains', []);

        if ($central !== [] && in_array('subdomain', (array) config('tenancy.identification.resolvers', []), true)) {
            return $tenant->getTenantSlug().'.'.$central[0];
        }

        return null;
    }

    public static function scheme(): string
    {
        return (string) (config('tenancy.url.scheme') ?: (self::inRequest() ? request()->getScheme() : 'https'));
    }

    /** True while a routed HTTP request is being handled (not a command, job or schedule). */
    public static function inRequest(): bool
    {
        if (! app()->runningInConsole()) {
            return true;
        }

        // Octane and the test client: a request that was routed.
        return app()->bound('request') && app('request')->route() !== null;
    }

    public function bootstrap(Tenant $tenant): void
    {
        if (self::inRequest() && ! config('tenancy.url.requests', false)) {
            return;
        }

        $host = self::hostFor($tenant);

        if ($host === null) {
            return;
        }

        $generator = app('url');
        $this->original = (fn () => [$this->forcedRoot, $this->forceScheme])->call($generator);
        $scheme = self::scheme();

        URL::forceRootUrl($scheme.'://'.$host);
        URL::forceScheme($scheme);
    }

    public function revert(): void
    {
        if ($this->original === null) {
            return;
        }

        [$root, $scheme] = $this->original;
        URL::forceRootUrl($root);
        URL::forceScheme($scheme === null ? null : rtrim($scheme, ':/'));
        $this->original = null;
    }
}
