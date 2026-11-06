<?php

declare(strict_types=1);

namespace Atannex\Tenancy\Console;

use Atannex\Tenancy\Domains\Domains;
use Atannex\Tenancy\Identification\Hosts;
use Atannex\Tenancy\Models\Domain;
use Illuminate\Console\Command;

final class VerifyDomainsCommand extends Command
{
    protected $signature = 'tenants:domains:verify {host? : One domain (default: every unverified domain)}';

    protected $description = 'Check DNS for unverified custom domains';

    public function handle(Domains $domains): int
    {
        $query = Domain::query()->whereNull('verified_at');
        $this->argument('host') && $query->where('host', Hosts::normalize((string) $this->argument('host')));
        $pending = 0;

        foreach ($query->get() as $domain) {
            $ok = $domains->verify($domain);
            $ok || $pending++;
            $record = $domains->record($domain);
            $this->components->twoColumnDetail($domain->host, $ok ? '<fg=green>verified</>' : "<fg=yellow>waiting for TXT {$record['name']} = {$record['value']}</>");
        }

        return $pending === 0 ? self::SUCCESS : self::FAILURE;
    }
}
