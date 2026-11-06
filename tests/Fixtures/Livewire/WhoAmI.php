<?php

declare(strict_types=1);

namespace Atannex\Tenancy\Tests\Fixtures\Livewire;

use Livewire\Component;

final class WhoAmI extends Component
{
    public string $who = 'nobody';

    public function check(): void
    {
        $this->who = tenant()?->getTenantSlug() ?? 'central';
    }

    public function render(): string
    {
        return '<div>who:{{ $who }}</div>';
    }
}
