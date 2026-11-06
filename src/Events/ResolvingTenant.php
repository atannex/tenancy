<?php

declare(strict_types=1);

namespace Atannex\Tenancy\Events;

use Illuminate\Http\Request;

final readonly class ResolvingTenant
{
    public function __construct(public Request $request) {}
}
