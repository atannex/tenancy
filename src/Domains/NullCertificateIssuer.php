<?php

declare(strict_types=1);

namespace Atannex\Tenancy\Domains;

use Atannex\Tenancy\Contracts\CertificateIssuer;
use Atannex\Tenancy\Models\Domain;

/** No issuing: the web server handles TLS (e.g. on-demand TLS via the ask endpoint). */
final class NullCertificateIssuer implements CertificateIssuer
{
    public function issue(Domain $domain): string
    {
        return 'external';
    }

    public function revoke(Domain $domain): void {}
}
