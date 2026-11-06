<?php

declare(strict_types=1);

namespace Atannex\Tenancy\Domains;

use Atannex\Tenancy\Enums\DomainType;
use Atannex\Tenancy\Identification\ResolverCache;
use Atannex\Tenancy\Models\Domain;

/** Where an alias host should redirect: the tenant's verified primary host, cached. */
final class AliasRedirect
{
    public static function key(string $host): string
    {
        return 'tenancy:alias:'.$host;
    }

    public static function target(string $host): ?string
    {
        $target = app(ResolverCache::class)->remember(self::key($host), function () use ($host): string {
            $domain = Domain::query()->where('host', $host)->first();

            if ($domain === null || $domain->type !== DomainType::Alias) {
                return '';
            }

            return (string) Domain::query()->where('tenant_id', $domain->tenant_id)
                ->where('type', DomainType::Primary->value)->whereNotNull('verified_at')->value('host');
        });

        return $target === '' || $target === null || $target === $host ? null : $target;
    }
}
