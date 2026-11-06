<?php

declare(strict_types=1);

namespace Atannex\Tenancy\Events;

/** The tenant row is gone; only its key and slug remain. */
final readonly class TenantPurged
{
    public function __construct(public string $tenant, public string $slug) {}
}
