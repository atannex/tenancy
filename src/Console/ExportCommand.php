<?php

declare(strict_types=1);

namespace Atannex\Tenancy\Console;

use Atannex\Tenancy\Console\Concerns\FindsTenant;
use Atannex\Tenancy\Lifecycle\Exporter;
use Illuminate\Console\Command;

final class ExportCommand extends Command
{
    use FindsTenant;

    protected $signature = 'tenants:export {tenant} {--path= : Zip file to write}';

    protected $description = 'Export all of a tenant\'s data and files to a zip';

    public function handle(): int
    {
        $path = app(Exporter::class)->export($this->tenant(), $this->option('path'));
        $this->components->info("Exported to [{$path}].");

        return self::SUCCESS;
    }
}
