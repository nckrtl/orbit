<?php

declare(strict_types=1);

namespace App\Infrastructure\Processes;

enum ProcessOutputStream: string
{
    case Stdout = 'stdout';
    case Stderr = 'stderr';
}
