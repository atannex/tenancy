<?php

declare(strict_types=1);

namespace Atannex\Tenancy\Models;

use Atannex\Tenancy\Contracts\Tenant;
use Atannex\Tenancy\Database\OnCentralConnection;
use Atannex\Tenancy\Domains\AliasRedirect;
use Atannex\Tenancy\Enums\DomainType;
use Atannex\Tenancy\Identification\Hosts;
use Atannex\Tenancy\Identification\ResolverCache;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $host
 * @property DomainType $type
 * @property Carbon|null $verified_at
 * @property string|null $verification_token
 * @property string|null $tls_status
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Tenant&Model $tenant
 */
class Domain extends Model
{
    use HasUlids, OnCentralConnection;

    protected $table = 'tenant_domains';

    /** verified_at and verification_token are set by Domains only: mass assignment can't skip the DNS proof. */
    protected $fillable = ['tenant_id', 'host', 'type', 'tls_status'];

    protected $attributes = ['type' => 'primary'];

    protected function casts(): array
    {
        return [
            'type' => DomainType::class,
            'verified_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $domain): void {
            if (! Hosts::isValid((string) $domain->host)) {
                throw new InvalidArgumentException("Domain [{$domain->host}] is not a valid hostname.");
            }

            foreach ((array) config('tenancy.identification.central_domains', []) as $central) {
                $central = Hosts::normalize($central);

                if ($domain->host === $central || str_ends_with($domain->host, '.'.$central)) {
                    throw new InvalidArgumentException("Domain [{$domain->host}] is on the central domain [{$central}]; tenant subdomains come from the slug.");
                }
            }

            if (static::verifiedElsewhere($domain->host, $domain->getKey())) {
                throw new InvalidArgumentException("Domain [{$domain->host}] is already verified by another tenant.");
            }
        });

        // Forget after commit, so a concurrent request cannot re-cache the old owner.
        $forget = function (self $domain): void {
            $hosts = array_filter([$domain->host, $domain->isDirty('host') ? $domain->getOriginal('host') : null]);
            // Alias targets depend on every domain of the tenant (old and new owner).
            $siblings = static::query()->whereIn('tenant_id', array_filter([$domain->tenant_id, $domain->getOriginal('tenant_id')]))->pluck('host')->all();

            $domain->getConnection()->afterCommit(function () use ($hosts, $siblings): void {
                $cache = app(ResolverCache::class);

                foreach ($hosts as $host) {
                    $cache->forget(ResolverCache::domainKey($host));
                }

                foreach (array_unique(array_merge($hosts, $siblings)) as $host) {
                    $cache->forget(AliasRedirect::key($host));
                }
            });
        };

        static::saved($forget);
        static::deleted($forget);
    }

    protected function host(): Attribute
    {
        return Attribute::set(fn (string $value) => Hosts::normalize($value));
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(config('tenancy.models.tenant'), 'tenant_id');
    }

    /** Is $host verified by any domain row other than $except? */
    public static function verifiedElsewhere(string $host, mixed $except = null): bool
    {
        return static::query()->where('host', Hosts::normalize($host))->whereNotNull('verified_at')
            ->when($except !== null, fn ($q) => $q->whereKeyNot($except))->exists();
    }

    public function isVerified(): bool
    {
        return $this->verified_at !== null;
    }
}
