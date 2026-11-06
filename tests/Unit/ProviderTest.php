<?php

declare(strict_types=1);

use Atannex\Tenancy\Tenancy;

it('merges the config', function () {
    expect(config('tenancy.strict'))->toBeTrue()
        ->and(config('tenancy.database.tenant_column'))->toBe('tenant_id');
});

it('pins the central connection to the default connection at boot', function () {
    expect(config('tenancy.database.central_connection'))->toBe('testing');
});

it('binds the manager as a singleton', function () {
    expect(app(Tenancy::class))->toBe(app(Tenancy::class));
});
