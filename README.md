# atannex/tenancy

Enterprise multi-tenancy for Laravel 12 and 13.

    composer require atannex/tenancy
    php artisan tenancy:install
    php artisan migrate

Add `BelongsToTenant` to every tenant-owned model, `$table->tenant()` to its migration, and put tenant routes behind the `tenant` middleware. With strict mode on, tenant data is never read without a tenant in context.

- **Isolation tiers per tenant:** shared database, PostgreSQL schema, or a database of its own (MySQL/MariaDB, PostgreSQL, SQLite, SQL Server), mixed freely; `tenants:promote` moves a tenant between them.
- **Identification:** domain, subdomain, path, header, session or the signed-in user.
- **Tenant-aware infrastructure:** cache, Redis, disks, sessions, URLs, mail, config, logs, Scout, queued jobs, broadcasting, the scheduler.
- **Lifecycle:** provisioning with retries and rollback, suspend, archive, purge, maintenance mode, export and import.
- **Custom domains:** DNS verification, alias redirects, a TLS hook.
- **Plans:** features, quotas, metering, settings; members, roles, invitations, impersonation; an audit log; a management API; and tenant-aware rate limits.

What the guards cannot see: `DB::table()` queries and `withoutGlobalScopes()` bypass tenant scoping, and models that define their own `newEloquentBuilder()` should use `GuardsTenantWrites` on that builder. The `header` and `path` resolvers let a client name any tenant, so pair them with a membership check (`tenant.member` or your own). Identify by session or user with `tenant.session`.
