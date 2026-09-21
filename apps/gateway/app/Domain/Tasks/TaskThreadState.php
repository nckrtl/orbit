<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

enum TaskThreadState: string
{
    case Idle = 'idle';
    case Pending = 'pending';
    case Finished = 'finished';
}
