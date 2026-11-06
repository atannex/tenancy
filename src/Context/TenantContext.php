<?php

declare(strict_types=1);

namespace Atannex\Tenancy\Context;

use Atannex\Tenancy\Contracts\Tenant;

/**
 * Frames of tenant context. A null frame is an explicit central frame,
 * entered with Tenancy::central(); an empty stack means "no context".
 */
final class TenantContext
{
    /** @var list<Tenant|null> */
    private array $frames = [];

    public function current(): ?Tenant
    {
        return $this->frames === [] ? null : $this->frames[array_key_last($this->frames)];
    }

    public function push(?Tenant $tenant): void
    {
        $this->frames[] = $tenant;
    }

    public function pop(): ?Tenant
    {
        return array_pop($this->frames);
    }

    public function depth(): int
    {
        return count($this->frames);
    }

    /** True when the innermost frame is an explicit central frame. */
    public function inCentral(): bool
    {
        return $this->frames !== [] && $this->current() === null;
    }

    public function flush(): void
    {
        $this->frames = [];
    }
}
