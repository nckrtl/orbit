<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

enum TaskThreadRole: string
{
    case Implementer = 'implementer';
    case Reviewer = 'reviewer';
}
