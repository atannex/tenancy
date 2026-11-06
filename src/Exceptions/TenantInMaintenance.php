<?php

declare(strict_types=1);

namespace Atannex\Tenancy\Exceptions;

use Atannex\Tenancy\Contracts\Tenant;

final class TenantInMaintenance extends TenancyException
{
    private ?int $retry = null;

    public static function for(Tenant $tenant, ?string $message, ?int $retry): self
    {
        $e = new self($message ?: "Tenant [{$tenant->getTenantSlug()}] is down for maintenance.");
        $e->retry = $retry;

        return $e;
    }

    /** The operator's own message (tenants:down --message) is meant for visitors. */
    public function publicMessage(): string
    {
        return $this->getMessage();
    }

    public function getStatusCode(): int
    {
        return 503;
    }

    public function getHeaders(): array
    {
        return $this->retry ? ['Retry-After' => (string) $this->retry] : [];
    }
}
