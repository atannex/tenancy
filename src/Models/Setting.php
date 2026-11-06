<?php

declare(strict_types=1);

namespace Atannex\Tenancy\Models;

use Atannex\Tenancy\Database\OnCentralConnection;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $tenant_id
 * @property string $key
 * @property mixed $value
 */
class Setting extends Model
{
    use OnCentralConnection;

    protected $table = 'tenant_settings';

    protected $fillable = ['tenant_id', 'key', 'value'];

    protected function casts(): array
    {
        return ['value' => 'json'];
    }
}
