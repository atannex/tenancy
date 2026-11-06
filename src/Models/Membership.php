<?php

declare(strict_types=1);

namespace Atannex\Tenancy\Models;

use Atannex\Tenancy\Database\OnCentralConnection;
use Atannex\Tenancy\Enums\MembershipStatus;
use Atannex\Tenancy\People\Users;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $user_id
 * @property string $role
 * @property MembershipStatus $status
 * @property Carbon|null $joined_at
 */
class Membership extends Model
{
    use HasUlids, OnCentralConnection;

    protected $table = 'tenant_memberships';

    protected $fillable = ['tenant_id', 'user_id', 'role', 'status', 'invited_by', 'joined_at'];

    protected $attributes = ['status' => 'active'];

    protected function casts(): array
    {
        return ['status' => MembershipStatus::class, 'joined_at' => 'datetime'];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(config('tenancy.models.tenant'), 'tenant_id');
    }

    public function user(): ?Model
    {
        return Users::find($this->user_id);
    }

    public function isActive(): bool
    {
        return $this->status === MembershipStatus::Active;
    }
}
