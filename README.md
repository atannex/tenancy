# atannex/tenancy

Enterprise multi-tenancy for Laravel 12 and 13.

    composer require atannex/tenancy
    php artisan tenancy:install
    php artisan migrate

Add `BelongsToTenant` to every tenant-owned model, add `$table->tenant()` to its migration, and put tenant routes behind the `tenant` middleware. With strict mode on, tenant data is never read without a tenant in context.

What the guards cannot see: `DB::table()` queries and `withoutGlobalScopes()` bypass tenant scoping, and models that define their own `newEloquentBuilder()` should use `GuardsTenantWrites` on that builder. The `header` and `path` resolvers let a client name any tenant, so pair them with your own membership check.
