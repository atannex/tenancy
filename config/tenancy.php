<?php

declare(strict_types=1);

use Atannex\Tenancy\Bootstrappers\CacheBootstrapper;
use Atannex\Tenancy\Bootstrappers\DatabaseBootstrapper;
use Atannex\Tenancy\Bootstrappers\FilesystemBootstrapper;
use Atannex\Tenancy\Bootstrappers\LogBootstrapper;
use Atannex\Tenancy\Bootstrappers\UrlBootstrapper;
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
        // Dedicated tiers: the connection whose server/credentials tenant databases are created on
        // (null = the central connection), the name tenants connect under, and database names.
        'template_connection' => env('TENANCY_TEMPLATE_CONNECTION'),
        'tenant_connection' => 'tenant',
        'prefix' => 'tenant_',
        'sqlite_path' => null, // null = database_path('tenants')
        // Give each dedicated tenant its own database login (MySQL/MariaDB/PostgreSQL).
        'dedicated_users' => false,
        'user_prefix' => 't_', // logins are prefix + a hash of the tenant key, at most 32 characters
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
        // Load the package migrations directly. Any you publish (tenancy:install) replace their package copy.
        'load_migrations' => true,
        // Point database-backed queues, cache stores, sessions and job batches that have no
        // connection of their own at the central connection, so dedicated tenants never swallow them.
        'pin_infrastructure' => true,
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
        // Fill {tenant} in route() wherever a tenant is in context (jobs, mail, commands).
        // Null = when 'path' is in resolvers; requests identified by path always get it.
        'path_defaults' => null,

        // Refuse suspended, archived and not-yet-active tenants in the `tenant` middleware.
        'require_active' => true,

        'cache' => [
            'enabled' => true,
            'store' => null,
            'ttl' => 3600,
            // Unknown hosts are remembered as misses for this many seconds (0 = off).
            'miss_ttl' => 60,
        ],
    ],

    // Classes implementing Atannex\Tenancy\Contracts\Bootstrapper, run in order on entering a tenant.
    // Also available: RedisBootstrapper, SessionBootstrapper,
    // ConfigBootstrapper, MailBootstrapper, ScoutBootstrapper.
    'bootstrappers' => [
        DatabaseBootstrapper::class,
        CacheBootstrapper::class,
        FilesystemBootstrapper::class,
        LogBootstrapper::class,
        // Links built in jobs, mail and commands point at the tenant's host.
        UrlBootstrapper::class,
    ],

    'cache' => [
        // Stores given a per-tenant key space. Null = the default store.
        'stores' => null,
    ],

    'redis' => [
        // Connections RedisBootstrapper prefixes per tenant. Never list the queue's connection
        // (Laravel's Redis queue uses "default"), or jobs land where no worker looks.
        'connections' => [],
    ],

    'filesystem' => [
        'disks' => ['local', 'public'],
        'suffix' => 'tenants/{key}',
        // Also give each tenant its own storage_path().
        'storage_path' => false,
    ],

    'url' => [
        'scheme' => 'https',
        // UrlBootstrapper leaves requests on the host they arrived on;
        // true roots links at the tenant's primary domain inside requests too.
        'requests' => false,
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
        // Seconds before a job held during tenants:promote or maintenance (hold_jobs) is tried again.
        // Holding does not spend one of the job's attempts on the database, Redis and SQS queues.
        'hold_delay' => 30,
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
        // tenants:import refuses zips beyond these (guards against zip bombs).
        'import' => [
            'max_entries' => 100_000,
            'max_bytes' => 2 * 1024 ** 3,
        ],
    ],

    'domains' => [
        'record' => '_atannex-tenancy',
        'auto_verify' => true,
        // Unverified claims expire, so nobody can hold a host they cannot prove.
        'claim_ttl_days' => 7,
        'verify_delays' => [60, 300, 900, 3600, 21600, 86400],
        'redirect_aliases' => true,
        // 301 is cached by browsers for good; use 302 while hosts are still moving.
        'alias_redirect_status' => 301,
        'dns_resolver' => PhpDnsResolver::class,
        'certificate_issuer' => NullCertificateIssuer::class,
        // On-demand TLS ask endpoint (GET {path}?domain=host).
        'tls_ask' => [
            'enabled' => false,
            'path' => 'tenancy/tls/ask',
            // Also answer for {slug}.{central domain} (reveals which slugs exist; off when you use a wildcard certificate).
            'subdomains' => false,
            'middleware' => ['throttle:120,1'],
        ],
    ],

    // The JSON management API, on central domains only. Off by default; guard it well.
    'api' => [
        'enabled' => false,
        'prefix' => 'tenancy-api',
        // Define Gate::define('tenancy.manage', …) for your platform staff.
        'middleware' => ['api', 'auth:sanctum', 'can:tenancy.manage'],
    ],

    'migrations' => [
        // Tenant migrations. Shared-tier tenants get them once on the central database.
        'paths' => [database_path('migrations/tenant')],
    ],

    'audit' => [
        'paths' => [app_path('Models')],
        'ignore_tables' => ['tenants', 'tenant_domains'],
    ],

    'integrations' => [
        // Livewire (when installed): updates re-run the page's tenancy middleware,
        // and temporary uploads live on a central copy of the upload disk.
        'livewire' => [
            'enabled' => true,
            'persistent_middleware' => true,
            'central_uploads' => true,
            // Extra middleware for Livewire's update route, e.g. ['web', 'tenant.optional']. Null = leave it.
            'update_middleware' => null,
        ],

        // Sanctum tokens tied to a tenant: publish tenancy-sanctum-migrations, then use
        // $user->createTenantToken() and the tenant.token middleware.
        'sanctum' => [
            'enabled' => false,
            'model' => null,
            'column' => 'tenant_id',
            // Let tokens tied to no tenant work inside tenants.
            'global_tokens' => false,
        ],
    ],
];
