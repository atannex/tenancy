<?php

declare(strict_types=1);

namespace Atannex\Tenancy\Identification;

final class Hosts
{
    /** "HTTPS://Acme.Example.com:8443/" → "acme.example.com". */
    public static function normalize(string $host): string
    {
        $host = strtolower(trim($host));
        $host = (string) preg_replace('#^[a-z][a-z0-9+.-]*://#', '', $host);
        $host = explode('/', $host, 2)[0];
        $host = (string) preg_replace('/:\d+$/', '', $host);

        return rtrim($host, '.');
    }

    /** @param list<string> $centralDomains */
    public static function isCentral(string $host, array $centralDomains): bool
    {
        return in_array(self::normalize($host), array_map(self::normalize(...), $centralDomains), true);
    }
}
