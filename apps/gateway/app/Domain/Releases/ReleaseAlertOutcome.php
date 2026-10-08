<?php

declare(strict_types=1);

namespace App\Domain\Releases;

enum ReleaseAlertOutcome: string
{
    case Done = 'done';
    case Skipped = 'skipped';
    case Failed = 'failed';
}
