<?php

declare(strict_types=1);

use Atannex\Tenancy\Identification\DomainResolver;
use Atannex\Tenancy\Identification\HeaderResolver;
use Atannex\Tenancy\Identification\PathResolver;
use Atannex\Tenancy\Identification\SubdomainResolver;
use Atannex\Tenancy\Models\Domain;
use Atannex\Tenancy\Models\Tenant;

return [

    'models' => [
        'tenant' => Tenant::class,
        'domain' => Domain::class,
    ],

    'database' => [
        // The connection that holds the tenants table. Null = the app's default connection at boot.
        'central_connection' => env('TENANCY_CENTRAL_CONNECTION'),
        'tenant_column' => 'tenant_id',
        // ulid | uuid | int — the type $table->tenant() creates.
        'key_type' => 'ulid',
        // Load the package migrations directly. Set false once you publish them (tenancy:install).
        'load_migrations' => true,
    ],

    // Fail closed: touching tenant models with no tenant (and outside Tenancy::central()) throws.
    'strict' => true,

    // Slugs that can never belong to a tenant (they collide with subdomains you run yourself).
    'reserved_slugs' => ['www', 'admin', 'api', 'app', 'mail', 'static', 'assets', 'cdn', 'status'],

    'identification' => [
        // Hosts that serve the central app. Subdomains of these resolve tenants by slug.
        'central_domains' => array_values(array_filter(explode(',', (string) env('TENANCY_CENTRAL_DOMAINS', 'localhost')))),

        // Resolvers the `tenant` middleware tries, in order, when given no arguments.
        'resolvers' => ['domain', 'subdomain'],

        'drivers' => [
            'domain' => DomainResolver::class,
            'subdomain' => SubdomainResolver::class,
            'path' => PathResolver::class,
            'header' => HeaderResolver::class,
        ],

        'header' => 'X-Tenant',
        'path_parameter' => 'tenant',

        // Refuse suspended, archived and not-yet-active tenants in the `tenant` middleware.
        'require_active' => true,

        'cache' => [
            'enabled' => true,
            'store' => null,
            'ttl' => 3600,
        ],
    ],

    // Classes implementing Atannex\Tenancy\Contracts\Bootstrapper, run in order on entering a tenant.
    'bootstrappers' => [],

    // End tenancy after each request (and each Octane request) so no state leaks.
    'reset_between_requests' => true,

    'audit' => [
        'paths' => [app_path('Models')],
        'ignore_tables' => ['tenants', 'tenant_domains'],
    ],

];
