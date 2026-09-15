<?php

declare(strict_types=1);

namespace App\Support\Console;

enum ProgressState
{
    case Waiting;
    case Running;
    case Success;
    case Failure;
    case Warning;
    case Skipped;

    public function terminal(): bool
    {
        return $this !== self::Waiting && $this !== self::Running;
    }
}
