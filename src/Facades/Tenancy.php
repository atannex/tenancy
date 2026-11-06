<?php

declare(strict_types=1);

namespace Atannex\Tenancy\Facades;

use Illuminate\Support\Facades\Facade;

/** @mixin \Atannex\Tenancy\Tenancy */
final class Tenancy extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Atannex\Tenancy\Tenancy::class;
    }
}
