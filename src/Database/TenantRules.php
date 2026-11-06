<?php

declare(strict_types=1);

namespace Atannex\Tenancy\Database;

use Atannex\Tenancy\Tenancy;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;
use Illuminate\Validation\Rules\Unique;

final class TenantRules
{
    public static function unique(string $table, string $column = 'NULL'): Unique
    {
        return (new Unique($table, $column))->where(self::column(), self::key());
    }

    public static function exists(string $table, string $column = 'NULL'): Exists
    {
        return (new Exists($table, $column))->where(self::column(), self::key());
    }

    public static function register(): void
    {
        Rule::macro('tenantUnique', fn (string $table, string $column = 'NULL') => TenantRules::unique($table, $column));
        Rule::macro('tenantExists', fn (string $table, string $column = 'NULL') => TenantRules::exists($table, $column));
    }

    private static function column(): string
    {
        return (string) config('tenancy.database.tenant_column', 'tenant_id');
    }

    private static function key(): string
    {
        return app(Tenancy::class)->currentOrFail('Tenant validation rules')->getTenantKey();
    }
}
