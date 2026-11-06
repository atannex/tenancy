# Changelog

## Unreleased (1.0.0 candidate)

- **Core:** tenant and domain models, a stacked tenant context with bootstrappers, and resolvers (domain, subdomain, path, header, session, user) with caching. Middleware: `tenant`, `tenant.optional`, `tenant.active`, `central`, `tenant.feature`.
- **Shared tier:**
  - `BelongsToTenant`, which fails closed in strict mode;
  - guarded saves, deletes and bulk writes (`TenantBuilder`);
  - `Rule::tenantUnique()` / `Rule::tenantExists()`;
  - the `$table->tenant()` / `tenantUnique()` migration helpers.
- **Infrastructure:**
  - bootstrappers for database, cache (per-tenant flush, locks, tags), Redis, filesystem, session, URL, config, mail, log context and Scout;
  - queued jobs that carry their tenant (`#[Central]` opts out);
  - `TenantChannel`, `tenants:run` and `Schedule::forEachTenant()`.
- **Dedicated tiers:**
  - database per tenant on MySQL/MariaDB, PostgreSQL, SQLite and SQL Server, plus a PostgreSQL schema tier;
  - per-tenant server overrides and optional dedicated logins;
  - `tenants:migrate|rollback|fresh|seed|status`.
- **Lifecycle:**
  - a guarded state machine;
  - recorded provisioning with retries, rollback or resume, queued if you like;
  - purge, export and import, and per-tenant maintenance mode;
  - `Tenancy::create()` and `Tenancy::fake()`.
- **Domains:** DNS TXT verification, a certificate issuer hook, alias redirects, primary switching and an on-demand TLS ask endpoint.
- **Plans:** plans, features with expiring overrides, quotas, metering with thresholds, typed settings, and a billing bridge.
- **People:** memberships, roles answered through the Gate, invitations, single-use impersonation, and an audit log with retention.
- **Operations:**
  - `tenants:promote` between tiers, with row counts verified;
  - the JSON management API;
  - `TenantRateLimit`;
  - `tenancy:doctor` and `tenancy:audit`.
