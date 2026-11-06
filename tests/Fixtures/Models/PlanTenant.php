<?php

declare(strict_types=1);

namespace Atannex\Tenancy\Tests\Fixtures\Models;

use Atannex\Tenancy\Models\Tenant;
use Atannex\Tenancy\Plans\InteractsWithPlans;

/** Tenant with the module helpers, until InteractsWithPlans is added to Tenant itself. */
final class PlanTenant extends Tenant
{
    use InteractsWithPlans;
}
