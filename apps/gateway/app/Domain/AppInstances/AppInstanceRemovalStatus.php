<?php

declare(strict_types=1);

namespace App\Domain\AppInstances;

enum AppInstanceRemovalStatus: string
{
    case Removing = 'removing';
    case Failed = 'failed';
    case Completed = 'completed';
}
