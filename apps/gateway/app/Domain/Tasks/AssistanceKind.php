<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

enum AssistanceKind: string
{
    case Direction = 'direction';
    case Failure = 'failure';
}
