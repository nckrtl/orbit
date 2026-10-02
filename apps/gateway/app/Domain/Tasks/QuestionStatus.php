<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

enum QuestionStatus: string
{
    case Open = 'open';
    case Escalated = 'escalated';
    case Answered = 'answered';
}
