<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

enum TaskCheckStatus: string
{
    case Running = 'running';
    case Passed = 'passed';
    case Failed = 'failed';
    case Changed = 'changed';
    case Lost = 'lost';
    case Cancelled = 'cancelled';
}
