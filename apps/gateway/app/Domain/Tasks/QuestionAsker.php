<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

enum QuestionAsker: string
{
    case Implementer = 'implementer';
    case Reviewer = 'reviewer';
    case Operator = 'operator';
}
