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
 */
final class UrlBootstrapper implements Bootstrapper
{
    /** @var array{0: ?string, 1: ?string}|null the app's own forced root and scheme */
    private ?array $original = null;

    public static function hostFor(Tenant $tenant): ?string
    {
        if (method_exists($tenant, 'domains')) {
            $host = $tenant->domains()->whereNotNull('verified_at')->orderByRaw("case when type = 'primary' then 0 else 1 end")->oldest()->value('host');

            if ($host) {
                return $host;
            }
        }

        $central = (array) config('tenancy.identification.central_domains', []);

        if ($central !== [] && in_array('subdomain', (array) config('tenancy.identification.resolvers', []), true)) {
            return $tenant->getTenantSlug().'.'.$central[0];
        }

        return null;
    }

    public function bootstrap(Tenant $tenant): void
    {
        $host = self::hostFor($tenant);

        if ($host === null) {
            return;
        }

        $generator = app('url');
        $this->original = (fn () => [$this->forcedRoot, $this->forceScheme])->call($generator);
        $scheme = (string) (config('tenancy.url.scheme') ?: (app()->runningInConsole() ? 'https' : request()->getScheme()));

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
