<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

enum TaskStatus: string
{
    case Backlog = 'backlog';
    case Todo = 'todo';
    case Reserved = 'reserved';
    case Running = 'running';
    case Reviewing = 'reviewing';
    case Settling = 'settling';
    case Completed = 'completed';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
}
