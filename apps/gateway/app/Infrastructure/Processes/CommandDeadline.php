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

    /** Seconds that callers hold back for later work of their own, such as a rollback; see `holding()`. */
    private float $heldSeconds = 0.0;

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

    /**
     * Runs one operation under its own deadline, then restores the deadline around it. The operation
     * never extends a running deadline, and it cannot clear the request's deadline for work after it.
     *
     * @template T
     *
     * @param  Closure(): T  $operation
     * @return T
     */
    public function within(float $seconds, Closure $operation): mixed
    {
        $saved = [$this->expiresAt, $this->seconds, $this->cleanupReserveSeconds, $this->exceeded];
        $this->start($seconds);

        try {
            return $operation();
        } finally {
            $exceeded = $this->exceeded;
            [$this->expiresAt, $this->seconds, $this->cleanupReserveSeconds, $this->exceeded] = $saved;
            // Once forward work ran out, the request's own cleanup keeps the reserve.
            $this->exceeded = $this->exceeded || $exceeded;
        }
    }

    /**
     * Runs work with `$seconds` more held back from it, for the work that must follow it whatever
     * happens, such as the rollback of a failed create. The hold ends with the work, so the later work
     * gets that time even after this work ran out of time.
     *
     * @template T
     *
     * @param  Closure(): T  $operation
     * @return T
     */
    public function holding(float $seconds, Closure $operation): mixed
    {
        $this->heldSeconds += $seconds;

        try {
            return $operation();
        } finally {
            $this->heldSeconds -= $seconds;
        }
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

        $remaining -= $this->heldSeconds;

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
