<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

enum AgentThreadState: string
{
    case Idle = 'idle';
    case Working = 'working';
    case AskingForInput = 'asking_for_input';
    case Done = 'done';
    case Failed = 'failed';
}
