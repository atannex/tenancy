<?php

declare(strict_types=1);

namespace Atannex\Tenancy\Audit;

use Atannex\Tenancy\Contracts\Tenant;
use BackedEnum;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Records the tenancy events named in tenancy.audit_log.events. The action
 * is the class basename in dot case (MemberAdded → member.added); a `tenant`
 * property gives the tenant, the first model property the subject, and
 * scalar properties the metadata.
 */
final class AuditSubscriber
{
    public function __construct(private readonly AuditRecorder $recorder) {}

    /** @param array<int, mixed> $payload */
    public function handle(string $eventName, array $payload): void
    {
        $event = $payload[0] ?? null;
        $basename = class_basename($eventName);

        if (! is_object($event) || ! in_array($basename, (array) config('tenancy.audit_log.events', []), true)) {
            return;
        }

        $tenant = null;
        $subject = null;
        $metadata = [];

        foreach (get_object_vars($event) as $name => $value) {
            if ($name === 'tenant') {
                $tenant = $value instanceof Tenant || is_string($value) ? $value : null;
            } elseif ($value instanceof Model && ! $value instanceof Tenant) {
                $subject ??= $value;
            } elseif ($value instanceof BackedEnum) {
                $metadata[$name] = $value->value;
            } elseif (is_scalar($value) || $value === null) {
                $metadata[$name] = $value;
            }
        }

        $this->recorder->record(Str::snake($basename, '.'), $subject, $metadata, $tenant);
    }
}
