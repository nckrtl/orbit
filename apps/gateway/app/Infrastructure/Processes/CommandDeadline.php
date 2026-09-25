<?php

declare(strict_types=1);

namespace App\Infrastructure\Processes;

use App\Domain\Shared\ResourceOperationException;
use Closure;
use Throwable;

final class CommandDeadline
{
    private ?float $expiresAt = null;

    private float $seconds = 0.0;

    /** @var Closure(): float */
    private readonly Closure $clock;

    /** @param (Closure(): float)|null $clock */
    public function __construct(?Closure $clock = null)
    {
        $this->clock = $clock ?? static fn (): float => microtime(true);
    }

    public function start(float $seconds): void
    {
        $this->seconds = $seconds;
        $this->expiresAt = ($this->clock)() + $seconds;
    }

    public function clear(): void
    {
        $this->expiresAt = null;
    }

    public function cap(float $localTimeout): float
    {
        if ($this->expiresAt === null) {
            return $localTimeout;
        }

        $remaining = $this->expiresAt - ($this->clock)();

        if ($remaining <= 0.0) {
            throw $this->exceeded();
        }

        return min($localTimeout, $remaining);
    }

    public function exceeded(?Throwable $previous = null): ResourceOperationException
    {
        return new ResourceOperationException(
            'command.deadline_exceeded',
            sprintf('The %d-second API command deadline was exceeded.', (int) round($this->seconds)),
            504,
            $previous,
        );
    }
}
