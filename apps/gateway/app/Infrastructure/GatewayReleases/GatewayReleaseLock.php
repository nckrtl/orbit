<?php

declare(strict_types=1);

namespace App\Infrastructure\GatewayReleases;

use App\Domain\GatewayReleases\GatewayReleaseException;
use Closure;

/**
 * The single-flight lock every release step runs under. It is a `flock` on a file in
 * `ORBIT_HOME`, held for the life of the process, so a crashed release frees it at once and no
 * time-to-live has to outlast a slow `composer install`.
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

        $handle = @fopen($this->path, 'c');

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

            try {
                return $operation();
            } finally {
                flock($handle, LOCK_UN);
            }
        } finally {
            fclose($handle);
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
