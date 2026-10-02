<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

enum TaskType: string
{
    case Implementation = 'implementation';
    case Annotation = 'annotation';
}
