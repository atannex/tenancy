<?php

declare(strict_types=1);

namespace Atannex\Tenancy\Console;

use Atannex\Tenancy\Support\IsolationAudit;
use Illuminate\Console\Command;

final class AuditCommand extends Command
{
    protected $signature = 'tenancy:audit';

    protected $description = 'Find tenant tables and models that are not isolated';

    public function handle(): int
    {
        $problems = IsolationAudit::run();

        if ($problems === []) {
            $this->components->info('No isolation problems found.');

            return self::SUCCESS;
        }

        foreach ($problems as $problem) {
            $this->components->error($problem);
        }

        return self::FAILURE;
    }
}
