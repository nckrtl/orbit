<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

enum TaskDefinitionStatus: string
{
    case Backlog = 'backlog';
    case Todo = 'todo';
}
