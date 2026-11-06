<?php

declare(strict_types=1);

use Atannex\Tenancy\Notifications\TenantInvitation;
use Atannex\Tenancy\Plans\ConfigPlanProvider;

/*
 * Plans, features, quotas, metering, settings, people and the audit log.
 * Each section is merged under "tenancy.{section}", so publish and override
 * them from config/tenancy.php like any other tenancy option.
 */
return [

    'plans' => [
        // A class implementing Atannex\Tenancy\Contracts\PlanProvider.
        'provider' => ConfigPlanProvider::class,

        // Plan used when a tenant has none.
        'default' => null,

        // 'pro' => ['name' => 'Pro', 'features' => ['sso'], 'limits' => ['seats' => 25, 'api_calls' => ['limit' => 10000, 'period' => 'month']]],
        'catalog' => [],
    ],

    'quotas' => [
        // HTTP status of QuotaExceeded: 402 (payment required) or 429.
        'status' => 402,
    ],

    'metering' => [
        // Period of a metric with no period in the plan: day, month, year or all.
        'default_period' => 'month',
        'periods' => [],

        // Percent of a limit at which UsageThresholdReached fires.
        'thresholds' => [80, 100],
    ],

    'settings' => [
        'defaults' => [],
        'cache_ttl' => 3600,
    ],

    'people' => [
        // Null = auth.providers.users.model.
        'user_model' => null,

        // Role => abilities (wildcards allowed), answered by Gate::before inside a tenant.
        'roles' => [
            'owner' => ['*'],
            'admin' => ['members.*', 'settings.*', 'billing.view', '*.view', '*.create', '*.update', '*.delete'],
            'member' => ['*.view', '*.create', '*.update'],
            'viewer' => ['*.view'],
        ],
        'default_role' => 'member',
        // The abilities tenant roles may answer through the Gate. Everything else is left to your
        // own policies, so a tenant owner never passes central checks. Add your tenant abilities.
        'gate_abilities' => ['members.*', 'settings.*', 'billing.*', 'tenant.*'],
        'gate' => true,

        // Quota key that limits active members; null = no seat limit.
        'seat_quota' => 'seats',

        // Where SessionResolver and switchTenant() keep the chosen tenant.
        'session_key' => 'tenancy.tenant',

        'invitations' => [
            'expires_in_days' => 7,
            'match_email' => true,
            // A notification class, or null to send nothing.
            'notification' => TenantInvitation::class,
            // Used when the package routes are off; {token} is replaced.
            'accept_url' => null,
        ],

        'impersonation' => [
            'ttl' => 60,
            'allow_owners' => false,
            'guard' => null,
        ],

        'routes' => [
            'enabled' => false,
            'prefix' => 'tenancy',
            'middleware' => ['web'],
            'after_accept' => '/',
            'after_impersonate' => '/',
        ],
    ],

    'audit_log' => [
        'enabled' => true,
        'retention_days' => 365,

        // Tenancy events (class basenames) recorded automatically.
        'events' => [
            'TenantCreated', 'TenantProvisioned', 'ProvisioningFailed', 'TenantSuspended', 'TenantReactivated',
            'TenantArchived', 'TenantRestored', 'TenantPurged', 'TenantPromoted',
            'DomainAdded', 'DomainVerified', 'DomainVerificationFailed', 'DomainRemoved',
            'TenantPlanChanged',
            'MemberAdded', 'MemberRemoved', 'MemberRoleChanged', 'MemberSuspended', 'MemberReactivated',
            'InvitationSent', 'InvitationAccepted', 'InvitationRevoked',
            'ImpersonationIssued', 'ImpersonationStarted', 'ImpersonationEnded',
        ],
    ],

    'billing' => [
        // Apply a plan when an atannex/billing payment succeeds with this metadata.
        'enabled' => true,
        'metadata' => ['tenant' => 'tenant', 'plan' => 'plan'],
    ],

];
