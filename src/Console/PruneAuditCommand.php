<?php

declare(strict_types=1);

namespace Atannex\Tenancy\Console;

use Atannex\Tenancy\Models\AuditEvent;
use Illuminate\Console\Command;

final class PruneAuditCommand extends Command
{
    protected $signature = 'tenancy:prune-audit {--days= : Keep this many days (default: tenancy.audit_log.retention_days)}';

    protected $description = 'Delete audit events older than the retention period';

    public function handle(): int
    {
        $days = (int) ($this->option('days') ?? config('tenancy.audit_log.retention_days', 365));
        $deleted = AuditEvent::query()->where('created_at', '<', now()->subDays($days))->delete();

        $this->components->info("Deleted {$deleted} audit event(s) older than {$days} days.");

        return self::SUCCESS;
    }
}
