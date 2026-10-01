<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

/**
 * Remembers whether the fetch before the current turn failed, so that turn's message can say so.
 * The scheduler clears it at the start of each fetch. One notice is shared for the process.
 */
final class TaskTurnFetchNotice
{
    public const string Failed = 'The fetch of origin failed. origin/* may be stale.';

    private bool $failed = false;

    public function clear(): void
    {
        $this->failed = false;
    }

    public function fail(): void
    {
        $this->failed = true;
    }

    public function apply(string $message): string
    {
        if (! $this->failed) {
            return $message;
        }

        return self::Failed."\n\n".$message;
    }
}
