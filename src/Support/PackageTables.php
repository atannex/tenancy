<?php

declare(strict_types=1);

namespace Atannex\Tenancy\Support;

/** Central tables owned by the package; never tenant data, so the isolation audit skips them. */
final class PackageTables
{
    public const array NAMES = [
        'tenants', 'tenant_domains',
        'tenant_feature_overrides', 'tenant_usage', 'tenant_settings',
        'tenant_memberships', 'tenant_invitations', 'tenant_impersonations', 'tenant_audit_events',
    ];
}
