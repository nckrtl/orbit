<?php

declare(strict_types=1);

namespace App\Infrastructure\Processes;

use App\Domain\Shared\ResourceOperationException;
use Closure;
use Throwable;

/**
 * One shared time budget for the remote work of a Gateway request.
 *
 * Forward work ends a cleanup reserve before the deadline. Once the deadline has cut forward work
 * short, the reserve is left for the rollback and cleanup that follow, so a request that runs out
 * of time can still undo what it started before PHP-FPM ends it.
 */
final class CommandDeadline
{
    /** The reserve every API command keeps for cleanup at the end of its deadline. */
    public const float CleanupReserveSeconds = 20.0;

    private ?float $expiresAt = null;

    private float $cleanupReserveSeconds = 0.0;

    private float $seconds = 0.0;

    private bool $exceeded = false;

    /** @var Closure(): float */
    private readonly Closure $clock;

    /** @param (Closure(): float)|null $clock */
    public function __construct(?Closure $clock = null)
    {
        $this->clock = $clock ?? static fn (): float => microtime(true);
    }

    /**
     * Starts the deadline. A deadline that is already running is never extended, so an operation
     * with a longer budget of its own still ends inside the request that runs it.
     */
    public function start(float $seconds, float $cleanupReserveSeconds = 0.0): void
    {
        $expiresAt = ($this->clock)() + $seconds;
        $running = $this->expiresAt !== null;

        if ($running && $this->expiresAt <= $expiresAt) {
            return;
        }

        $this->seconds = $seconds;
        $this->expiresAt = $expiresAt;
        // A shorter nested deadline keeps the cleanup reserve of the request around it.
        $this->cleanupReserveSeconds = $running
            ? max($this->cleanupReserveSeconds, $cleanupReserveSeconds)
            : $cleanupReserveSeconds;
        $this->exceeded = false;
    }

    public function clear(): void
    {
        $this->expiresAt = null;
        $this->cleanupReserveSeconds = 0.0;
        $this->exceeded = false;
    }

    public function cap(float $localTimeout): float
    {
        if ($this->expiresAt === null) {
            return $localTimeout;
        }

        $remaining = $this->expiresAt - ($this->clock)();

        if (! $this->exceeded) {
            $remaining -= $this->cleanupReserveSeconds;
        }

        if ($remaining <= 0.0) {
            throw $this->exceeded();
        }

        return min($localTimeout, $remaining);
    }

    /** Marks forward work as over, which releases the cleanup reserve, and returns the error to throw. */
    public function exceeded(?Throwable $previous = null): ResourceOperationException
    {
        $this->exceeded = true;

        return new ResourceOperationException(
            'command.deadline_exceeded',
            sprintf('The %d-second command deadline was exceeded.', (int) round($this->seconds)),
            504,
            $previous,
        );
    }
}
