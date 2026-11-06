<?php

declare(strict_types=1);

namespace Atannex\Tenancy\Console;

use Atannex\Tenancy\Support\IsolationAudit;
use Atannex\Tenancy\Tenancy;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

final class DoctorCommand extends Command
{
    protected $signature = 'tenancy:doctor';

    protected $description = 'Check the tenancy setup';

    private bool $failed = false;

    public function handle(Tenancy $tenancy): int
    {
        $connection = $tenancy->centralConnection();

        $this->check('Central database', function () use ($connection) {
            DB::connection($connection)->getPdo();

            return "connection [{$connection}] reachable";
        });

        $this->check('Tenant tables', fn () => Schema::connection($connection)->hasTable('tenants') && Schema::connection($connection)->hasTable('tenant_domains')
            ? 'migrated'
            : throw new \RuntimeException('run php artisan migrate'));

        $this->check('Tenant model', fn () => $tenancy->model().' ('.$tenancy->query()->count().' tenants)');

        $this->check('Resolvers', function () {
            $drivers = (array) config('tenancy.identification.drivers', []);

            foreach ((array) config('tenancy.identification.resolvers', []) as $name) {
                isset($drivers[$name]) ? app($drivers[$name]) : throw new \RuntimeException("unknown resolver [{$name}]");
            }

            return implode(', ', (array) config('tenancy.identification.resolvers', []));
        });

        $this->check('Bootstrappers', fn () => count($tenancy->bootstrappers()).' configured');

        $this->warnUnless('Strict mode', $tenancy->strict(), 'on', 'off — tenant data can be read with no tenant in context');

        $central = (array) config('tenancy.identification.central_domains', []);
        $this->warnUnless('Central domains', $central !== [] || ! in_array('subdomain', (array) config('tenancy.identification.resolvers', []), true),
            $central === [] ? 'none' : implode(', ', $central), 'none set, so the subdomain resolver can never match');

        $this->check('Isolation audit', function () {
            $problems = IsolationAudit::run();

            return $problems === [] ? 'clean' : throw new \RuntimeException(count($problems).' problem(s); run php artisan tenancy:audit');
        });

        return $this->failed ? self::FAILURE : self::SUCCESS;
    }

    /** @param \Closure(): string $probe */
    private function check(string $label, \Closure $probe): void
    {
        try {
            $this->components->twoColumnDetail($label, '<fg=green>✔ '.$probe().'</>');
        } catch (Throwable $e) {
            $this->failed = true;
            $this->components->twoColumnDetail($label, '<fg=red>✖ '.$e->getMessage().'</>');
        }
    }

    private function warnUnless(string $label, bool $ok, string $good, string $bad): void
    {
        $this->components->twoColumnDetail($label, $ok ? "<fg=green>✔ {$good}</>" : "<fg=yellow>! {$bad}</>");
    }
}
