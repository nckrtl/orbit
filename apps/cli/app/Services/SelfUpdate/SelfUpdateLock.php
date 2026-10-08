<?php

declare(strict_types=1);

namespace App\Services\SelfUpdate;

use Closure;
use Illuminate\Support\Sleep;

/**
 * One `orbit self-update` at a time on a machine. As root on Linux the lock is `/run/lock/orbit-self-update.lock`,
 * which the Gateway's agent converge also takes when it moves a new agent into place.
 */
final readonly class SelfUpdateLock
{
    public const int WaitSeconds = 120;

    /**
     * @template TResult
     *
     * @param  Closure(): TResult  $operation
     * @return TResult
     *
     * @throws SelfUpdateFailure
     */
    public function hold(string $path, Closure $operation, int $waitSeconds = self::WaitSeconds): mixed
    {
        $directory = dirname($path);

        if (! is_dir($directory) && ! @mkdir($directory, 0700, true)) {
            throw new SelfUpdateFailure('self_update.lock_failed', "Cannot create the self-update lock in {$directory}.");
        }

        $handle = @fopen($path, 'c');

        if ($handle === false) {
            throw new SelfUpdateFailure('self_update.lock_failed', "Cannot open the self-update lock {$path}.");
        }

        try {
            for ($waited = 0; ! flock($handle, LOCK_EX | LOCK_NB); $waited++) {
                if ($waited >= $waitSeconds) {
                    throw new SelfUpdateFailure('self_update.busy', 'Another orbit self-update, or an agent converge, is running on this machine.');
                }

                Sleep::for(1)->second();
            }

            try {
                return $operation();
            } finally {
                flock($handle, LOCK_UN);
            }
        } finally {
            fclose($handle);
        }
    }
}
