<?php

declare(strict_types=1);

namespace App\Support\Logs;

final class SystemLogFollowClock implements LogFollowClock
{
    #[\Override]
    public function now(): float
    {
        return microtime(true);
    }

    #[\Override]
    public function sleep(float $seconds): void
    {
        if ($seconds > 0) {
            usleep((int) round($seconds * 1_000_000));
        }
    }
}
