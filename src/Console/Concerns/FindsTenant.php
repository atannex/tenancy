<?php

declare(strict_types=1);

namespace Atannex\Tenancy\Console\Concerns;

use Atannex\Tenancy\Contracts\Tenant;
use Atannex\Tenancy\Tenancy;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;

/** @mixin Command */
trait FindsTenant
{
    protected function tenant(): Tenant&Model
    {
        /** @var Tenant&Model */
        return app(Tenancy::class)->findOrFail((string) $this->argument('tenant'));
    }
}
