<?php

declare(strict_types=1);

namespace Atannex\Tenancy\Lifecycle;

use Atannex\Tenancy\Contracts\Tenant;
use Atannex\Tenancy\Exceptions\TenantInMaintenance;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Per-tenant maintenance mode, stored in the tenant's data. Visiting any
 * page with ?tenancy_secret={secret} sets a bypass cookie for 12 hours.
 */
final class Maintenance
{
    public const string PARAMETER = 'tenancy_secret';

    /** @param array{secret?: ?string, retry?: ?int, message?: ?string, hold_jobs?: bool} $options */
    public function down(Tenant&Model $tenant, array $options = []): void
    {
        $data = (array) ($tenant->getAttribute('data') ?? []);
        $data['maintenance'] = [
            'secret' => empty($options['secret']) ? null : hash('sha256', (string) $options['secret']),
            'retry' => $options['retry'] ?? null,
            'message' => $options['message'] ?? null,
            'hold_jobs' => (bool) ($options['hold_jobs'] ?? false),
            'since' => now()->toIso8601String(),
        ];

        $tenant->forceFill(['data' => $data])->save();
    }

    public function up(Tenant&Model $tenant): void
    {
        $data = (array) ($tenant->getAttribute('data') ?? []);
        unset($data['maintenance']);
        $tenant->forceFill(['data' => $data])->save();
    }

    /** While data moves, the tenant's queued jobs wait. */
    public function holdsJobs(Tenant $tenant): bool
    {
        return (bool) ($this->state($tenant)['hold_jobs'] ?? false);
    }

    public function isDown(Tenant $tenant): bool
    {
        return $this->state($tenant) !== null;
    }

    /** Throws while down; returns a redirect that sets the bypass cookie when the secret matches. */
    public function check(Request $request, Tenant $tenant): ?RedirectResponse
    {
        $state = $this->state($tenant);

        if ($state === null) {
            return null;
        }

        $cookie = 'tenancy_bypass_'.substr(hash('sha256', $tenant->getTenantKey()), 0, 12);
        $secret = $state['secret'] ?? null;

        if ($secret !== null) {
            // Keyed by the app key too, so the stored hash alone (e.g. in an export) cannot forge it.
            $proof = hash_hmac('sha256', $tenant->getTenantKey().'|'.$secret, (string) config('app.key'));

            if (hash_equals($proof, (string) $request->cookies->get($cookie))) {
                return null;
            }

            if (hash_equals($secret, hash('sha256', (string) $request->query(self::PARAMETER)))) {
                $url = $request->fullUrlWithoutQuery([self::PARAMETER]);

                $redirect = new RedirectResponse($url);
                $redirect->headers->setCookie(Cookie::create($cookie, $proof, now()->addHours(12)));

                return $redirect;
            }
        }

        throw TenantInMaintenance::for($tenant, $state['message'] ?? null, $state['retry'] ?? null);
    }

    /** @return array<string, mixed>|null */
    private function state(Tenant $tenant): ?array
    {
        $data = $tenant instanceof Model ? (array) ($tenant->getAttribute('data') ?? []) : [];

        return isset($data['maintenance']) ? (array) $data['maintenance'] : null;
    }
}
