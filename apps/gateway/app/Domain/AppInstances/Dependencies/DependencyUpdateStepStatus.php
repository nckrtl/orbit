<?php

declare(strict_types=1);

namespace App\Domain\AppInstances\Dependencies;

enum DependencyUpdateStepStatus: string
{
    case Succeeded = 'succeeded';
    case Absent = 'absent';
    case Failed = 'failed';
    case NotRun = 'not_run';
}
