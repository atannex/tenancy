<?php

declare(strict_types=1);

namespace Atannex\Tenancy\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

final class InstallCommand extends Command
{
    protected $signature = 'tenancy:install';

    protected $description = 'Publish the tenancy config and migrations';

    public function handle(): int
    {
        $this->call('vendor:publish', ['--tag' => 'tenancy-config']);
        $this->call('vendor:publish', ['--tag' => 'tenancy-migrations']);

        $folder = database_path('migrations/tenant');
        File::ensureDirectoryExists($folder);
        File::put($folder.'/.gitkeep', '');

        $this->components->info('Tenancy installed. Run php artisan migrate (published migrations replace the package copies), then add BelongsToTenant to tenant models.');

        return self::SUCCESS;
    }
}
