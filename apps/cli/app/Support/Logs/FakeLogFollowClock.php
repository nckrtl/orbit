<?php

declare(strict_types=1);

namespace App\Support\Logs;

use Closure;

/**
 * A LogFollowClock double for tests. sleep() advances the clock without waiting and then calls
 * the tick callback with the new time and the number of sleeps so far, which lets a test feed
 * the fake socket, change a mock response, or record Ctrl-C at a chosen moment.
 */
final class FakeLogFollowClock implements LogFollowClock
{
    public int $sleeps = 0;

    /** @var null|Closure(float, int): void */
    private ?Closure $tick = null;

    public function __construct(private float $now = 1_000_000.0) {}

    /** @param  Closure(float, int): void  $tick */
    public function onTick(Closure $tick): self
    {
        $this->tick = $tick;

        return $this;
    }

    #[\Override]
    public function now(): float
    {
        return $this->now;
    }

    #[\Override]
    public function sleep(float $seconds): void
    {
        $this->now += max(0.0, $seconds);
        $this->sleeps++;

        if ($this->tick instanceof Closure) {
            ($this->tick)($this->now, $this->sleeps);
        }
    }
}
