<?php

declare(strict_types=1);

namespace Atannex\Tenancy\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;

/** Has a tenant_id column but forgot BelongsToTenant — tenancy:audit must flag it. */
final class LeakyNote extends Model
{
    public $timestamps = false;

    protected $fillable = ['tenant_id', 'body'];
}
