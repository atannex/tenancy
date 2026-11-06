<?php

declare(strict_types=1);

namespace Atannex\Tenancy\Identification;

use Atannex\Tenancy\Contracts\Tenant;
use Illuminate\Support\Facades\URL;

/**
 * Keeps the {tenant} URL default in step with the tenant in context, so
 * route() fills it in for "/{tenant}/..." routes. It follows nested run()
 * calls and is cleared when tenancy ends, so links never point at a previous
 * tenant.
 *
 * It is on when the request was identified by path, and everywhere (queued
 * jobs, mail, commands) when the path resolver is configured or
 * tenancy.identification.path_defaults is true. Nothing is kept between
 * requests: the request flag lives on the request itself.
 */
final class PathDefaults
{
    private const ATTRIBUTE = 'tenancy.path_identified';

    public function activate(Tenant $tenant): void
    {
        if (app()->bound('request')) {
            app('request')->attributes->set(self::ATTRIBUTE, true);
        }

        $this->apply($tenant->getTenantSlug());
    }

    public function initialized(Tenant $tenant): void
    {
        if ($this->active()) {
            $this->apply($tenant->getTenantSlug());
        }
    }

    public function ended(): void
    {
        if ($this->active()) {
            $this->apply(null);
        }
    }

    public function active(): bool
    {
        // Null = on when the path resolver is configured; false still allows path-identified requests.
        $everywhere = config('tenancy.identification.path_defaults')
            ?? in_array('path', (array) config('tenancy.identification.resolvers', []), true);

        if ($everywhere) {
            return true;
        }

        return app()->bound('request') && app('request')->attributes->get(self::ATTRIBUTE) === true;
    }

    private function apply(?string $slug): void
    {
        URL::defaults([(string) config('tenancy.identification.path_parameter', 'tenant') => $slug]);
    }
}
