<?php

declare(strict_types=1);

namespace Atannex\Tenancy\Jobs;

use Atannex\Tenancy\Attributes\Central;
use Atannex\Tenancy\Domains\Domains;
use Atannex\Tenancy\Models\Domain;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/** Checks DNS with growing delays until the record appears or attempts run out. */
#[Central]
final class VerifyDomain implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public function __construct(public readonly string $domain) {}

    public function tries(): int
    {
        return count($this->delays()) + 1;
    }

    public function handle(Domains $domains): void
    {
        $domain = Domain::query()->find($this->domain);

        if ($domain === null || $domains->verify($domain)) {
            return;
        }

        $delays = $this->delays();
        $attempt = $this->attempts();

        if ($this->job !== null && $attempt <= count($delays)) {
            $this->release($delays[$attempt - 1]);
        }
    }

    /** @return list<int> */
    private function delays(): array
    {
        return array_values((array) config('tenancy.domains.verify_delays', [60, 300, 900, 3600, 21600, 86400]));
    }
}
