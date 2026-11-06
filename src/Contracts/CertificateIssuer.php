<?php

declare(strict_types=1);

namespace Atannex\Tenancy\Contracts;

use Atannex\Tenancy\Models\Domain;

/** Issues TLS certificates for verified custom domains (ACME, a CDN API, …). */
interface CertificateIssuer
{
    /** @return string the tls_status to record, e.g. "issued" or "pending" */
    public function issue(Domain $domain): string;

    public function revoke(Domain $domain): void;
}
