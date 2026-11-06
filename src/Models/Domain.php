<?php

declare(strict_types=1);

namespace Atannex\Tenancy\Models;

use Atannex\Tenancy\Database\OnCentralConnection;
use Atannex\Tenancy\Enums\DomainType;
use Atannex\Tenancy\Identification\Hosts;
use Atannex\Tenancy\Identification\ResolverCache;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $host
 * @property DomainType $type
 * @property Carbon|null $verified_at
 */
class Domain extends Model
{
    use HasUlids, OnCentralConnection;

    protected $table = 'tenant_domains';

    protected $fillable = ['tenant_id', 'host', 'type', 'verified_at', 'verification_token', 'tls_status'];

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
        $forget = function (self $domain): void {
            $cache = app(ResolverCache::class);
            $cache->forget(ResolverCache::domainKey($domain->host));

            if ($domain->isDirty('host') && $domain->getOriginal('host')) {
                $cache->forget(ResolverCache::domainKey($domain->getOriginal('host')));
            }
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

    public function isVerified(): bool
    {
        return $this->verified_at !== null;
    }
}
