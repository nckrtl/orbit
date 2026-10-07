<?php

declare(strict_types=1);

namespace App\Infrastructure\GatewayReleases;

use App\Domain\GatewayReleases\GatewayReleaseException;
use Closure;

/**
 * The single-flight lock every release step runs under. It is a `flock` on a file in
 * `ORBIT_HOME`, held for the life of the process, so a crashed release frees it at once and no
 * time-to-live has to outlast a slow `composer install`. The file is opened close-on-exec, so a
 * child that outlives the release, such as a detached `git gc`, never holds the lock.
 */
final readonly class GatewayReleaseLock
{
    public function __construct(private string $path) {}

    /**
     * @template TResult
     *
     * @param  Closure(): TResult  $operation
     * @return TResult
     */
    public function run(Closure $operation): mixed
    {
        $directory = dirname($this->path);

        if (! is_dir($directory) && ! @mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw $this->unavailable();
        }

        $handle = @fopen($this->path, 'ce');

        if ($handle === false) {
            throw $this->unavailable();
        }

        try {
            if (! flock($handle, LOCK_EX | LOCK_NB)) {
                throw new GatewayReleaseException(
                    step: 'lock',
                    errorCode: 'gateway.release_in_progress',
                    message: 'Another Gateway release step is running. Wait for it to finish.',
                );
            }

            $this->assertNoStepRunning();

            try {
                return $operation();
            } finally {
                flock($handle, LOCK_UN);
            }
        } finally {
            fclose($handle);
        }
    }

    /** The lock that a release step's commands hold while they run, next to this lock. */
    public function stepPath(): string
    {
        return dirname($this->path).'/gateway-release-step.lock';
    }

    /**
     * Refuses while a command from an earlier release step still runs, for example a migration that went on after
     * the process that started it died.
     */
    private function assertNoStepRunning(): void
    {
        $step = @fopen($this->stepPath(), 'ce');

        if ($step === false) {
            return;
        }

        try {
            if (! flock($step, LOCK_EX | LOCK_NB)) {
                throw new GatewayReleaseException(
                    step: 'lock',
                    errorCode: 'gateway.release_in_progress',
                    message: 'A command from an earlier release step still runs, such as a migration whose release process died. Wait for it to finish.',
                );
            }

            flock($step, LOCK_UN);
        } finally {
            fclose($step);
        }
    }

    private function unavailable(): GatewayReleaseException
    {
        return new GatewayReleaseException(
            step: 'lock',
            errorCode: 'gateway.release_lock_unavailable',
            message: "The Gateway release lock [{$this->path}] cannot be opened.",
            status: 500,
        );
    }
}
