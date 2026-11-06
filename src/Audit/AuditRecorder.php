<?php

declare(strict_types=1);

namespace Atannex\Tenancy\Audit;

use Atannex\Tenancy\Contracts\Tenant;
use Atannex\Tenancy\Models\AuditEvent;
use Atannex\Tenancy\Tenancy;
use Illuminate\Database\Eloquent\Model;

final class AuditRecorder
{
    /** @param array<string, mixed> $metadata */
    public function record(string $action, ?Model $subject = null, array $metadata = [], Tenant|string|null $tenant = null): ?AuditEvent
    {
        if (! config('tenancy.audit_log.enabled', true)) {
            return null;
        }

        $tenant ??= app(Tenancy::class)->current();
        $actor = app()->bound('auth') ? auth()->user() : null;
        $request = app()->bound('request') && ! app()->runningInConsole() ? request() : null;

        return AuditEvent::query()->create([
            'tenant_id' => $tenant instanceof Tenant ? $tenant->getTenantKey() : $tenant,
            'actor_type' => $actor instanceof Model ? $actor->getMorphClass() : null,
            'actor_id' => $actor instanceof Model ? (string) $actor->getKey() : null,
            'action' => $action,
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject ? (string) $subject->getKey() : null,
            'ip' => $request?->ip(),
            'user_agent' => $request ? mb_substr((string) $request->userAgent(), 0, 512) : null,
            'metadata' => $metadata === [] ? null : $metadata,
        ]);
    }
}
