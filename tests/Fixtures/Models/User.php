<?php

declare(strict_types=1);

namespace Atannex\Tenancy\Tests\Fixtures\Models;

use Atannex\Tenancy\People\HasTenants;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

final class User extends Authenticatable
{
    use HasTenants, Notifiable;

    protected $fillable = ['name', 'email'];
}
