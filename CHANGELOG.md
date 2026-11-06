# Changelog

## [1.0.1] - 2026-11-06

- A package migration is skipped only when the app holds a copy published from this package. The copy is recognised by the new "Published by atannex/tenancy" marker, or by the central connection key it reads, so copies published by 1.0.0 still count. Another package's migration with the same name no longer hides it.

## [1.0.0] - 2026-11-06

First release.

- **Core:** tenant and domain models, a stacked tenant context with bootstrappers, and resolvers (domain, subdomain, path, header, session, user) with caching. Middleware: `tenant`, `tenant.optional`, `tenant.active`, `central`, `tenant.feature`.
- **Shared tier:**
  - `BelongsToTenant`, which fails closed in strict mode;
  - guarded saves, deletes and bulk writes (`TenantBuilder`);
  - `Rule::tenantUnique()` / `Rule::tenantExists()`;
  - the `$table->tenant()` / `tenantUnique()` migration helpers.
- **Integrations:**
  - Livewire updates and temporary uploads run in the page's tenant;
  - opt-in tenant-bound Sanctum tokens (`createTenantToken()`, the `tenant.token` middleware);
  - `tenant_route()` and `tenant_url()`, and `{tenant}` path defaults in jobs, mail and commands.
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
- **Hardening:**
  - Dedicated tenants get their own connection name, so a model loaded in one tenant always saves to that tenant's database.
  - `#[Central]` is honoured on queued listeners, notifications, mail and broadcasts.
  - Membership changes run under a tenant row lock, so last-owner and seat checks can't race.
  - The maintenance bypass is keyed by the app key, and exports leave out maintenance and provisioning data.
  - The TLS ask endpoint answers only verified domains by default (`tls_ask.subdomains` opts in) and is throttled.
  - Several tenants may claim a host until one verifies it; rival claims are then dropped, and stale claims expire.
  - Queued tenant-database commands refuse to run once the tenant has moved to the shared tier.
  - Nested central domains resolve.
  - JSON errors outside debug mode show public messages only.
  - The audit checks each model's own tenant column and connection, works with table prefixes, and uses a tokenizer-based model finder.
- The `tenant` middleware re-checks status and maintenance when a tenant was already set earlier in the request.
- `$table->tenant()` always adds an explicit index (PostgreSQL and SQLite don't index foreign keys).
- Writes Eloquent forwards to the base query are guarded:
  - `updateOrInsert`, `insertUsing`, `insertOrIgnoreUsing`, `insertOrIgnoreReturning` and `updateFrom` are scoped or stamped;
  - `truncate` is central-only;
  - increments can't change the tenant column.
- On MySQL and MariaDB, an `upsert` can no longer take over another tenant's row through a global unique key.
- Quiet saves, creates, updates and deletes are guarded without relying on model events.
- Queues, batches, failed jobs, the database cache and database sessions are pinned to the central connection (`tenancy.database.pin_infrastructure`).
- The default filesystem disk is rebuilt for each tenant.
- After-response jobs keep their tenant, and an open transaction survives `central()`.
- Jobs held during maintenance or promotion don't use up an attempt.
- Published migrations are never loaded twice.
- Export, import and promotion list only the tenant's own schema.
  - They stream rows and files.
  - Import enforces size limits.
  - Package tables are included.
  - Shared-tier id collisions are refused.
- Purge fires `TenantPurging`, clears package tables and Redis keys. Tenants are no longer soft-deleted: archive, then purge.
- Dedicated database passwords never reach query logs or stored errors, and creating a login can be retried.
- Lifecycle transitions are claimed atomically.
- Each invitation is accepted at most once.
- Impersonation ends when the session moves to another tenant or outlives `session_ttl`.
- Concurrent verification claims on one host are serialised.
- Hosts are validated and normalised to punycode, and unknown hosts are cached briefly.
- The billing bridge checks the plan, the price and (optionally) membership.
- The modules config merges at every depth and is publishable.
- `tenants:seed` and `--seed` run the tenant seeder, never the central `DatabaseSeeder`.
- `tenancy:prune-audit` is scheduled.
