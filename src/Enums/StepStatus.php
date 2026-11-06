<?php

declare(strict_types=1);

namespace Atannex\Tenancy\Enums;

enum StepStatus: string
{
    case Pending = 'pending';
    case Running = 'running';
    case Done = 'done';
    case Failed = 'failed';
    case RolledBack = 'rolled_back';
}
