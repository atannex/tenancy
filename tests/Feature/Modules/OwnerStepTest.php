<?php

declare(strict_types=1);

use Atannex\Tenancy\People\Provisioning\CreateOwnerMembership;

require_once __DIR__.'/../../Modules.php';

it('makes the owner a member and rolls back', function () {
    $tenant = planTenant();
    $user = moduleUser();
    $step = app(CreateOwnerMembership::class);

    $step->handle($tenant, ['owner' => $user->id]);
    $step->handle($tenant, ['owner' => $user->id]);
    expect($user->tenantRole($tenant))->toBe('owner');

    $step->rollback($tenant, ['owner' => $user->id]);
    expect($user->belongsToTenant($tenant))->toBeFalse();
});

it('does nothing without an owner', function () {
    app(CreateOwnerMembership::class)->handle(planTenant(), []);
})->throwsNoExceptions();
