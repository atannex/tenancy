<?php

declare(strict_types=1);

namespace Atannex\Tenancy\Integrations\Sanctum;

use Atannex\Tenancy\Database\OnCentralConnection;
use Laravel\Sanctum\PersonalAccessToken as SanctumToken;

/**
 * Sanctum's token model, kept on the central connection (users are central,
 * and on dedicated tiers the default connection points at the tenant), with
 * the tenant a token was issued for in tenant_id (null = not tied to one).
 */
class PersonalAccessToken extends SanctumToken
{
    use OnCentralConnection;
}
