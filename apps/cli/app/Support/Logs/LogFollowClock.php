<?php

declare(strict_types=1);

namespace App\Support\Logs;

/** The time source and wait of a log follow loop, so tests can drive its lease and poll timers. */
interface LogFollowClock
{
    /** Seconds since the Unix epoch. */
    public function now(): float;

    public function sleep(float $seconds): void;
}
