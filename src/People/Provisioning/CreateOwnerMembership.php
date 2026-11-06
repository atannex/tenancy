<?php

declare(strict_types=1);

namespace Atannex\Tenancy\People\Provisioning;

use Atannex\Tenancy\Contracts\ProvisioningStep;
use Atannex\Tenancy\Contracts\Tenant;
use Atannex\Tenancy\Models\Membership;
use Atannex\Tenancy\People\Members;
use Atannex\Tenancy\People\Users;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/** Makes $options['owner'] (a user key) the tenant's owner. */
final class CreateOwnerMembership implements ProvisioningStep
{
    public function __construct(private readonly Members $members) {}

    public function handle(Tenant $tenant, array $options): void
    {
        if (empty($options['owner'])) {
            return;
        }

        $user = Users::find($options['owner']) ?? throw (new ModelNotFoundException)->setModel(Users::model(), [$options['owner']]);

        $this->members->add($tenant, $user, Members::OWNER);
    }

    public function rollback(Tenant $tenant, array $options): void
    {
        if (empty($options['owner'])) {
            return;
        }

        Membership::query()->where('tenant_id', $tenant->getTenantKey())->where('user_id', (string) $options['owner'])->delete();
    }
}
