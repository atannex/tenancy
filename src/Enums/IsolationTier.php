<?php

declare(strict_types=1);

namespace Atannex\Tenancy\Enums;

enum IsolationTier: string
{
    case Shared = 'shared';
    case Schema = 'schema';
    case Database = 'database';
}
