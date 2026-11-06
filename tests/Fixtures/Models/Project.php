<?php

declare(strict_types=1);

namespace Atannex\Tenancy\Tests\Fixtures\Models;

use Atannex\Tenancy\Database\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

final class Project extends Model
{
    use BelongsToTenant;

    protected $fillable = ['name', 'slug', 'tenant_id'];
}
