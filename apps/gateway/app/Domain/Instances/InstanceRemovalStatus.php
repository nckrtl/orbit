<?php

declare(strict_types=1);

namespace App\Domain\Instances;

enum InstanceRemovalStatus: string
{
    case Removing = 'removing';
    case Failed = 'failed';
    case Completed = 'completed';
}
