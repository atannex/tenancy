<?php

declare(strict_types=1);

namespace Atannex\Tenancy\Models;

use Atannex\Tenancy\Database\OnCentralConnection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $impersonator_id
 * @property string $user_id
 * @property Carbon $expires_at
 * @property Carbon|null $used_at
 * @property Carbon|null $ended_at
 */
class Impersonation extends Model
{
    use HasUlids, OnCentralConnection;

    protected $table = 'tenant_impersonations';

    protected $fillable = ['tenant_id', 'impersonator_id', 'user_id', 'token_hash', 'expires_at', 'used_at', 'ended_at', 'ip'];

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'used_at' => 'datetime', 'ended_at' => 'datetime'];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(config('tenancy.models.tenant'), 'tenant_id');
    }
}
