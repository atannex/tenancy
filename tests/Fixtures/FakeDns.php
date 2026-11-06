<?php

declare(strict_types=1);

namespace Atannex\Tenancy\Tests\Fixtures;

use Atannex\Tenancy\Contracts\DnsResolver;

final class FakeDns implements DnsResolver
{
    /** @var array<string, list<string>> */
    public static array $records = [];

    public function txt(string $host): array
    {
        return self::$records[$host] ?? [];
    }
}
