<?php

declare(strict_types=1);

namespace Atannex\Tenancy\Identification;

use Atannex\Tenancy\Contracts\Tenant;
use Illuminate\Support\Facades\URL;

/**
 * Keeps the {tenant} URL default in step with the tenant in context once a
 * request was identified by path: it follows nested run() calls and is
 * cleared when tenancy ends, so links never point at a previous tenant.
 */
final class PathDefaults
{
    private bool $active = false;

    public function activate(Tenant $tenant): void
    {
        $this->active = true;
        $this->apply($tenant->getTenantSlug());
    }

    public function initialized(Tenant $tenant): void
    {
        if ($this->active) {
            $this->apply($tenant->getTenantSlug());
        }
    }

    public function ended(): void
    {
        if ($this->active) {
            $this->apply(null);
        }
    }

    private function apply(?string $slug): void
    {
        URL::defaults([(string) config('tenancy.identification.path_parameter', 'tenant') => $slug]);
    }
}
