<?php

declare(strict_types=1);

namespace Atannex\Tenancy\Tests\Fixtures\Models;

use Atannex\Tenancy\People\HasTenants;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;

final class ApiUser extends Authenticatable
{
    use HasApiTokens, HasTenants;

    protected $table = 'users';

    protected $fillable = ['name', 'email'];
}
