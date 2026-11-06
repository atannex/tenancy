<?php

declare(strict_types=1);

namespace Atannex\Tenancy\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;

/** A dedicated-tier model with no tenant scope at all. */
final class PlainNote extends Model
{
    protected $table = 'notes';

    protected $fillable = ['body', 'tenant_id'];
}
