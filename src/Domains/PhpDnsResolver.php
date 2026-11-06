<?php

declare(strict_types=1);

namespace Atannex\Tenancy\Domains;

use Atannex\Tenancy\Contracts\DnsResolver;

final class PhpDnsResolver implements DnsResolver
{
    public function txt(string $host): array
    {
        $records = @dns_get_record($host, DNS_TXT);

        return is_array($records) ? array_map(fn (array $r) => (string) ($r['txt'] ?? ''), $records) : [];
    }
}
