<?php

declare(strict_types=1);

namespace Atannex\Tenancy\Tests\Fixtures\AuditModels;

use Atannex\Tenancy\Database\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * Mentions classes in its docblock, and Other::class must not confuse the finder.
class Decoy is not the model (a docblock line without a leading asterisk).
 */
final class Tricky extends Model
{
    use BelongsToTenant;

    protected $table = 'tricky_rows';

    public function getTenantColumn(): string
    {
        return 'workspace_id';
    }
}
