<?php

declare(strict_types=1);

namespace Atannex\Tenancy\Database;

/** Pins a model to the central connection, whatever connection a tenant switched to. */
trait OnCentralConnection
{
    public function getConnectionName(): ?string
    {
        return config('tenancy.database.central_connection') ?: parent::getConnectionName();
    }
}
