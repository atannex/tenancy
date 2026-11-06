<?php

declare(strict_types=1);

namespace Atannex\Tenancy\Database;

use Illuminate\Database\Eloquent\Builder;

/**
 * @template TModel of \Illuminate\Database\Eloquent\Model
 *
 * @extends Builder<TModel>
 */
class TenantBuilder extends Builder
{
    use GuardsTenantWrites;
}
