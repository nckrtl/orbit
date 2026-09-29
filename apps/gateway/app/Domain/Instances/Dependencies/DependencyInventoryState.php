<?php

declare(strict_types=1);

namespace App\Domain\Instances\Dependencies;

enum DependencyInventoryState: string
{
    case Unknown = 'unknown';
    case Present = 'present';
    case Absent = 'absent';
    case Stale = 'stale';
}
