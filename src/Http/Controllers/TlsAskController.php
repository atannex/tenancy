<?php

declare(strict_types=1);

namespace Atannex\Tenancy\Http\Controllers;

use Atannex\Tenancy\Enums\TenantStatus;
use Atannex\Tenancy\Identification\Hosts;
use Atannex\Tenancy\Models\Domain;
use Atannex\Tenancy\Tenancy;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * On-demand TLS "ask" endpoint (Caddy and friends): 200 only for hosts this
 * app really serves — verified custom domains and active tenant subdomains.
 */
final class TlsAskController
{
    public function __invoke(Request $request, Tenancy $tenancy): Response
    {
        $host = Hosts::normalize((string) $request->query('domain', ''));

        if ($host === '') {
            return response('missing domain', 400);
        }

        $known = Domain::query()->where('host', $host)->whereNotNull('verified_at')
            ->whereHas('tenant', fn ($q) => $q->where('status', TenantStatus::Active->value))->exists();

        foreach ((array) config('tenancy.identification.central_domains', []) as $central) {
            $central = Hosts::normalize($central);
            $slug = str_ends_with($host, '.'.$central) ? substr($host, 0, -strlen('.'.$central)) : '';

            if ($host === $central || ($slug !== '' && ! str_contains($slug, '.') && $tenancy->query()->where('slug', $slug)->where('status', TenantStatus::Active->value)->exists())) {
                $known = true;
            }
        }

        return $known ? response('ok') : response('unknown domain', 404);
    }
}
