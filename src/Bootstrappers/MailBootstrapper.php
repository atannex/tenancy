<?php

declare(strict_types=1);

namespace Atannex\Tenancy\Bootstrappers;

/** Mail from-address and name from the tenant (tenancy.mail.from.* paths). */
final class MailBootstrapper extends ConfigBootstrapper
{
    protected function map(): array
    {
        return array_filter([
            'mail.from.address' => config('tenancy.mail.from.address'),
            'mail.from.name' => config('tenancy.mail.from.name'),
        ]);
    }
}
