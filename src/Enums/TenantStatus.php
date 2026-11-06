<?php

declare(strict_types=1);

namespace Atannex\Tenancy\Enums;

enum TenantStatus: string
{
    case Pending = 'pending';
    case Provisioning = 'provisioning';
    case Active = 'active';
    case Suspended = 'suspended';
    case Archived = 'archived';
    case Failed = 'failed';

    /** Only active tenants are served. */
    public function isAccessible(): bool
    {
        return $this === self::Active;
    }
}
