<?php

declare(strict_types=1);

namespace App\Domain\Apps;

enum AppUpdateStatus: string
{
    case Reserved = 'reserved';
    case Preflighted = 'preflighted';
    case Prepared = 'prepared';
    case Publishing = 'publishing';
    case CleaningUp = 'cleaning_up';
    case Complete = 'complete';
    case RollingBack = 'rolling_back';
    case RolledBack = 'rolled_back';

    public function isIncomplete(): bool
    {
        return ! in_array($this, [self::Complete, self::RolledBack], true);
    }

    public function recoversForward(): bool
    {
        return in_array($this, [self::Publishing, self::CleaningUp], true);
    }
}
