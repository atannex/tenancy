<?php

declare(strict_types=1);

namespace Atannex\Tenancy\Models;

use Atannex\Tenancy\Database\OnCentralConnection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property string $tenant_id
 * @property string $feature
 * @property bool $enabled
 * @property Carbon|null $expires_at
 */
class FeatureOverride extends Model
{
    use OnCentralConnection;

    protected $table = 'tenant_feature_overrides';

    protected $fillable = ['tenant_id', 'feature', 'enabled', 'expires_at'];

    protected function casts(): array
    {
        return ['enabled' => 'boolean', 'expires_at' => 'datetime'];
    }

    public function isLive(): bool
    {
        return $this->expires_at === null || $this->expires_at->isFuture();
    }
}
