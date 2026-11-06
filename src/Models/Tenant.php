<?php

declare(strict_types=1);

namespace Atannex\Tenancy\Models;

use Atannex\Tenancy\Contracts\Tenant as TenantContract;
use Atannex\Tenancy\Database\Factories\TenantFactory;
use Atannex\Tenancy\Database\OnCentralConnection;
use Atannex\Tenancy\Enums\DomainType;
use Atannex\Tenancy\Enums\IsolationTier;
use Atannex\Tenancy\Enums\TenantStatus;
use Atannex\Tenancy\Identification\ResolverCache;
use Atannex\Tenancy\Plans\InteractsWithPlans;
use Atannex\Tenancy\Tenancy;
use Closure;
use Illuminate\Database\Eloquent\Casts\AsArrayObject;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * @property string $id
 * @property string $slug
 * @property string $name
 * @property TenantStatus $status
 * @property IsolationTier $isolation
 * @property array<string, mixed>|null $connection
 * @property string|null $plan
 * @property \ArrayObject<string, mixed>|null $data
 */
class Tenant extends Model implements TenantContract
{
    /** @use HasFactory<TenantFactory> */
    use HasFactory, HasUlids, InteractsWithPlans, OnCentralConnection, SoftDeletes;

    /** One DNS label: lowercase letters, digits and inner hyphens, 1–63 chars. */
    public const string SLUG_PATTERN = '/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/';

    protected $table = 'tenants';

    protected $fillable = [
        'slug', 'name', 'status', 'isolation', 'connection', 'plan', 'data',
        'trial_ends_at', 'suspended_at', 'archived_at', 'purge_after',
    ];

    protected $attributes = [
        'status' => 'active',
        'isolation' => 'shared',
    ];

    protected function casts(): array
    {
        return [
            'status' => TenantStatus::class,
            'isolation' => IsolationTier::class,
            'connection' => 'encrypted:array',
            'data' => AsArrayObject::class,
            'trial_ends_at' => 'datetime',
            'suspended_at' => 'datetime',
            'archived_at' => 'datetime',
            'purge_after' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $tenant): void {
            if (blank($tenant->slug)) {
                $tenant->slug = Str::slug((string) $tenant->name);
            }

            $tenant->slug = strtolower((string) $tenant->slug);

            if (! preg_match(self::SLUG_PATTERN, $tenant->slug)) {
                throw new InvalidArgumentException("Tenant slug [{$tenant->slug}] must be one DNS label: a-z, 0-9 and inner hyphens, at most 63 characters.");
            }

            if (Str::isUlid($tenant->slug) || Str::isUuid($tenant->slug)) {
                throw new InvalidArgumentException("Tenant slug [{$tenant->slug}] looks like a tenant key; slugs and keys must never collide.");
            }

            if (in_array($tenant->slug, (array) config('tenancy.reserved_slugs', []), true)) {
                throw new InvalidArgumentException("Tenant slug [{$tenant->slug}] is reserved.");
            }
        });

        // Forget after commit, so a concurrent request cannot re-cache stale keys.
        $forget = function (self $tenant): void {
            $keys = [ResolverCache::slugKey($tenant->slug)];

            if ($tenant->getOriginal('slug')) {
                $keys[] = ResolverCache::slugKey($tenant->getOriginal('slug'));
            }

            foreach ($tenant->domains()->pluck('host') as $host) {
                $keys[] = ResolverCache::domainKey($host);
            }

            $tenant->getConnection()->afterCommit(function () use ($keys): void {
                foreach ($keys as $key) {
                    app(ResolverCache::class)->forget($key);
                }
            });
        };

        static::saved($forget);
        static::deleted($forget);
    }

    protected static function newFactory(): TenantFactory
    {
        return TenantFactory::new();
    }

    public function domains(): HasMany
    {
        return $this->hasMany(config('tenancy.models.domain'), 'tenant_id');
    }

    public function primaryDomain(): ?Domain
    {
        return $this->domains()->where('type', DomainType::Primary->value)->oldest()->first();
    }

    /** Run a callback inside this tenant. */
    public function run(Closure $callback): mixed
    {
        return app(Tenancy::class)->run($this, $callback);
    }

    public function getTenantKey(): string
    {
        return (string) $this->getKey();
    }

    public function getTenantSlug(): string
    {
        return (string) $this->slug;
    }

    public function tenantStatus(): TenantStatus
    {
        return $this->status;
    }

    public function isolationTier(): IsolationTier
    {
        return $this->isolation;
    }
}
