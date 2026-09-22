<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

enum TaskSessionNextAction: string
{
    case EscalateCoder = 'escalate_coder';
    case Noop = 'noop';
}
