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
        $host = rtrim($host, '.');

        // Internationalised names are stored and matched in their ASCII (punycode) form.
        if (preg_match('/[^\x20-\x7e]/', $host) && function_exists('idn_to_ascii')) {
            $ascii = idn_to_ascii($host, IDNA_NONTRANSITIONAL_TO_ASCII, INTL_IDNA_VARIANT_UTS46);
            $host = $ascii === false ? $host : strtolower($ascii);
        }

        return $host;
    }

    /** A fully qualified hostname: at least two DNS labels of a-z, 0-9 and inner hyphens, 253 chars at most. */
    public static function isValid(string $host): bool
    {
        return strlen($host) <= 253
            && preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+(?:[a-z](?:[a-z0-9-]{0,61}[a-z0-9])?)$/', $host) === 1;
    }

    /** @param list<string> $centralDomains */
    public static function isCentral(string $host, array $centralDomains): bool
    {
        return in_array(self::normalize($host), array_map(self::normalize(...), $centralDomains), true);
    }
}
