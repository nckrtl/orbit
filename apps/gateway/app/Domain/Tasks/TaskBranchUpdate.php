<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

enum TaskBranchUpdate: string
{
    case Accepted = 'accepted';
    case Conflict = 'conflict';
    case Unavailable = 'unavailable';
}
