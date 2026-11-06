<?php

declare(strict_types=1);

use Atannex\Tenancy\Bootstrappers\CacheBootstrapper;
use Atannex\Tenancy\Bootstrappers\DatabaseBootstrapper;
use Atannex\Tenancy\Bootstrappers\FilesystemBootstrapper;
use Atannex\Tenancy\Bootstrappers\LogBootstrapper;
use Atannex\Tenancy\Domains\NullCertificateIssuer;
use Atannex\Tenancy\Domains\PhpDnsResolver;
use Atannex\Tenancy\Identification\DomainResolver;
use Atannex\Tenancy\Identification\HeaderResolver;
use Atannex\Tenancy\Identification\PathResolver;
use Atannex\Tenancy\Identification\SubdomainResolver;
use Atannex\Tenancy\Isolation\Managers\MySqlDatabaseManager;
use Atannex\Tenancy\Isolation\Managers\PostgresDatabaseManager;
use Atannex\Tenancy\Isolation\Managers\SqliteDatabaseManager;
use Atannex\Tenancy\Isolation\Managers\SqlServerDatabaseManager;
use Atannex\Tenancy\Lifecycle\Steps\CreateDatabase;
use Atannex\Tenancy\Lifecycle\Steps\CreatePrimaryDomain;
use Atannex\Tenancy\Lifecycle\Steps\MigrateDatabase;
use Atannex\Tenancy\Lifecycle\Steps\SeedDatabase;
use Atannex\Tenancy\Models\Domain;
use Atannex\Tenancy\Models\Tenant;
use Atannex\Tenancy\People\Provisioning\CreateOwnerMembership;

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
        // Dedicated tiers: the connection whose server/credentials tenant databases are created on
        // (null = the central connection), the name tenants connect under, and database names.
        'template_connection' => env('TENANCY_TEMPLATE_CONNECTION'),
        'tenant_connection' => 'tenant',
        'prefix' => 'tenant_',
        'sqlite_path' => null, // null = database_path('tenants')
        // Give each dedicated tenant its own database login (MySQL/MariaDB/PostgreSQL).
        'dedicated_users' => false,
        'user_hosts' => ['%', 'localhost'], // MySQL/MariaDB account hosts for dedicated users
        // Tier for new tenants: shared | schema | database.
        'default_tier' => 'shared',
        'managers' => [
            'mysql' => MySqlDatabaseManager::class,
            'mariadb' => MySqlDatabaseManager::class,
            'pgsql' => PostgresDatabaseManager::class,
            'sqlite' => SqliteDatabaseManager::class,
            'sqlsrv' => SqlServerDatabaseManager::class,
        ],
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
    // Also available: RedisBootstrapper, SessionBootstrapper, UrlBootstrapper,
    // ConfigBootstrapper, MailBootstrapper, ScoutBootstrapper.
    'bootstrappers' => [
        DatabaseBootstrapper::class,
        CacheBootstrapper::class,
        FilesystemBootstrapper::class,
        LogBootstrapper::class,
    ],

    'cache' => [
        // Stores given a per-tenant key space. Null = the default store.
        'stores' => null,
    ],

    'redis' => [
        // Connections prefixed per tenant. Never list the queue's connection.
        'connections' => ['default'],
    ],

    'filesystem' => [
        'disks' => ['local', 'public'],
        'suffix' => 'tenants/{key}',
        // Also give each tenant its own storage_path().
        'storage_path' => false,
    ],

    'url' => [
        'scheme' => 'https',
    ],

    // ConfigBootstrapper: config key => tenant path (data_get, or "settings.x").
    'config_map' => [],

    'mail' => [
        'from' => [
            'address' => 'data.mail.from_address',
            'name' => 'name',
        ],
    ],

    'queue' => [
        // Run jobs of suspended/archived tenants anyway (default: the job fails).
        'run_inactive' => false,
    ],

    // End tenancy after each request (and each Octane request) so no state leaks.
    'reset_between_requests' => true,

    'provisioning' => [
        // Run provisioning on the queue (ProvisionTenant job) instead of inline.
        'queue' => false,
        'tries' => 3,
        'backoff_ms' => 500,
        // On final failure undo finished steps (retry starts over). Off: retry resumes at the failed step.
        'rollback_on_failure' => true,
        'seeder' => null,
        'steps' => [
            CreateDatabase::class,
            MigrateDatabase::class,
            SeedDatabase::class,
            CreatePrimaryDomain::class,
            CreateOwnerMembership::class,
        ],
    ],

    'lifecycle' => [
        // Days between archiving and purging.
        'grace_days' => 30,
        // Schedule tenants:purge --due daily and tenants:domains:verify hourly.
        'schedule' => true,
        // Where the shared tier's tenant models live (null = tenancy.audit.paths).
        'model_paths' => null,
    ],

    'domains' => [
        'record' => '_atannex-tenancy',
        'auto_verify' => true,
        'verify_delays' => [60, 300, 900, 3600, 21600, 86400],
        'redirect_aliases' => true,
        'dns_resolver' => PhpDnsResolver::class,
        'certificate_issuer' => NullCertificateIssuer::class,
        // On-demand TLS ask endpoint (GET {path}?domain=host).
        'tls_ask' => [
            'enabled' => false,
            'path' => 'tenancy/tls/ask',
        ],
    ],

    // The JSON management API, on central domains only. Off by default; guard it well.
    'api' => [
        'enabled' => false,
        'prefix' => 'tenancy-api',
        'middleware' => ['api', 'auth:sanctum', 'abilities:tenancy:manage'],
    ],

    'migrations' => [
        // Tenant migrations. Shared-tier tenants get them once on the central database.
        'paths' => [database_path('migrations/tenant')],
    ],

    'audit' => [
        'paths' => [app_path('Models')],
        'ignore_tables' => ['tenants', 'tenant_domains'],
    ],

];
