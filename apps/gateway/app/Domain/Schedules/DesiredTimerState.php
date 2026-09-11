<?php

declare(strict_types=1);

namespace App\Domain\Schedules;

enum DesiredTimerState: string
{
    case Enabled = 'enabled';
    case Disabled = 'disabled';
}
