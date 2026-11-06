<?php

declare(strict_types=1);

namespace Atannex\Tenancy\Tests\Fixtures\AuditModels;

use Atannex\Tenancy\Database\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/** Uses BelongsToTenant but its table has no tenant column. */
final class Columnless extends Model
{
    use BelongsToTenant;

    protected $table = 'columnless_rows';
}
