<?php

declare(strict_types=1);

namespace Atannex\Tenancy\Models;

use Atannex\Tenancy\Database\OnCentralConnection;
use Atannex\Tenancy\Enums\StepStatus;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * One step of one provisioning run.
 *
 * @property string $tenant_id
 * @property string $run
 * @property int $position
 * @property string $step
 * @property StepStatus $status
 * @property int $attempts
 * @property string|null $error
 */
class ProvisioningStep extends Model
{
    use HasUlids, OnCentralConnection;

    protected $table = 'tenant_provisioning_steps';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['status' => StepStatus::class, 'started_at' => 'datetime', 'finished_at' => 'datetime'];
    }
}
