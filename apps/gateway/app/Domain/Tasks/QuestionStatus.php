<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

enum QuestionStatus: string
{
    case Open = 'open';
    case Escalated = 'escalated';
    case Answered = 'answered';
    case Superseded = 'superseded';

    /** @return list<self> */
    public static function unresolved(): array
    {
        return [self::Open, self::Escalated];
    }
}
