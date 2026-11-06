<?php

declare(strict_types=1);

namespace Atannex\Tenancy\Domains;

use Atannex\Tenancy\Contracts\CertificateIssuer;
use Atannex\Tenancy\Contracts\DnsResolver;
use Atannex\Tenancy\Contracts\Tenant;
use Atannex\Tenancy\Enums\DomainType;
use Atannex\Tenancy\Events\DomainAdded;
use Atannex\Tenancy\Events\DomainRemoved;
use Atannex\Tenancy\Events\DomainVerificationFailed;
use Atannex\Tenancy\Events\DomainVerified;
use Atannex\Tenancy\Jobs\VerifyDomain;
use Atannex\Tenancy\Models\Domain;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Custom domains: added unverified with a token, verified by a DNS TXT
 * record at "_atannex-tenancy.{host}" (tenancy.domains.record), then
 * handed to the certificate issuer.
 */
final class Domains
{
    public function __construct(private readonly DnsResolver $dns, private readonly CertificateIssuer $certificates) {}

    public function add(Tenant $tenant, string $host, DomainType $type = DomainType::Alias, bool $verified = false): Domain
    {
        /** @var Domain $domain */
        $domain = $tenant->domains()->create([
            'host' => $host,
            'type' => $type,
            'verification_token' => Str::random(40),
            'verified_at' => $verified ? now() : null,
        ]);

        event(new DomainAdded($tenant, $domain));

        if ($verified) {
            $this->dropRivalClaims($domain);
            $this->issue($tenant, $domain);
        } elseif (config('tenancy.domains.auto_verify', true)) {
            VerifyDomain::dispatch($domain->getKey());
        }

        return $domain;
    }

    public function remove(Domain $domain): void
    {
        $tenant = $domain->tenant;
        $host = $domain->host;

        if ($domain->isVerified()) {
            $this->certificates->revoke($domain);
        }

        $domain->delete();
        event(new DomainRemoved($tenant, $host));
    }

    /** Make this domain the tenant's only primary one; the others become aliases. */
    public function makePrimary(Domain $domain): void
    {
        DB::connection($domain->getConnectionName())->transaction(function () use ($domain): void {
            $domain->tenant->domains()->whereKeyNot($domain->getKey())->where('type', DomainType::Primary->value)->get()
                ->each(fn (Domain $other) => $other->update(['type' => DomainType::Alias]));
            $domain->update(['type' => DomainType::Primary]);
        });
    }

    /** @return array{type: string, name: string, value: string} */
    public function record(Domain $domain): array
    {
        return ['type' => 'TXT', 'name' => config('tenancy.domains.record', '_atannex-tenancy').'.'.$domain->host, 'value' => (string) $domain->verification_token];
    }

    public function verify(Domain $domain): bool
    {
        if ($domain->isVerified()) {
            return true;
        }

        $record = $this->record($domain);
        $values = array_map('trim', $this->dns->txt($record['name']));
        $tenant = $domain->tenant;

        if ($record['value'] === '' || ! in_array($record['value'], $values, true)) {
            event(new DomainVerificationFailed($tenant, $domain, "TXT record {$record['name']} does not contain the verification token."));

            return false;
        }

        $domain->update(['verified_at' => now()]);
        $this->dropRivalClaims($domain);
        event(new DomainVerified($tenant, $domain));
        $this->issue($tenant, $domain);

        return true;
    }

    /** The verified owner wins: other tenants' unverified claims on the host go. */
    private function dropRivalClaims(Domain $domain): void
    {
        Domain::query()->where('host', $domain->host)->whereKeyNot($domain->getKey())->whereNull('verified_at')->get()->each->delete();
    }

    /** Unverified claims older than tenancy.domains.claim_ttl_days. */
    public function expireClaims(): int
    {
        $stale = Domain::query()->whereNull('verified_at')->where('created_at', '<', now()->subDays((int) config('tenancy.domains.claim_ttl_days', 7)))->get();
        $stale->each->delete();

        return $stale->count();
    }

    private function issue(Tenant $tenant, Domain $domain): void
    {
        $domain->update(['tls_status' => $this->certificates->issue($domain)]);
    }
}
