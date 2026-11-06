<?php

declare(strict_types=1);

use Atannex\Tenancy\Integrations\Billing\ApplyPaidPlan;

require_once __DIR__.'/../../Modules.php';

beforeEach(fn () => definePlans());

it('moves a tenant to the plan a payment paid for', function () {
    $tenant = planTenant('free');
    $event = (object) ['payment' => (object) ['metadata' => ['tenant' => $tenant->id, 'plan' => 'pro']]];

    app(ApplyPaidPlan::class)->handle($event);

    expect($tenant->fresh()->plan)->toBe('pro');
});

it('ignores payments without tenancy metadata', function () {
    $tenant = planTenant('free');

    app(ApplyPaidPlan::class)->handle((object) ['payment' => (object) ['metadata' => ['order' => 5]]]);

    expect($tenant->fresh()->plan)->toBe('free');
});
