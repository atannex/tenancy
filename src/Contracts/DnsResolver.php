<?php

declare(strict_types=1);

namespace Atannex\Tenancy\Contracts;

interface DnsResolver
{
    /** @return list<string> the TXT values published at $host */
    public function txt(string $host): array;
}
