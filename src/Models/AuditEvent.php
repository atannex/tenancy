<?php

declare(strict_types=1);

namespace Atannex\Tenancy\Models;

use Atannex\Tenancy\Database\OnCentralConnection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string|null $tenant_id
 * @property string|null $actor_type
 * @property string|null $actor_id
 * @property string $action
 * @property string|null $subject_type
 * @property string|null $subject_id
 * @property array<string, mixed>|null $metadata
 * @property Carbon $created_at
 */
class AuditEvent extends Model
{
    use HasUlids, OnCentralConnection;

    public const UPDATED_AT = null;

    protected $table = 'tenant_audit_events';

    protected $fillable = ['tenant_id', 'actor_type', 'actor_id', 'action', 'subject_type', 'subject_id', 'ip', 'user_agent', 'metadata'];

    protected function casts(): array
    {
        return ['metadata' => 'array', 'created_at' => 'datetime'];
    }
}
