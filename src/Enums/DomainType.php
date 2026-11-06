<?php

declare(strict_types=1);

namespace Atannex\Tenancy\Enums;

enum DomainType: string
{
    case Primary = 'primary';
    case Alias = 'alias';
    case Subdomain = 'subdomain';
}
