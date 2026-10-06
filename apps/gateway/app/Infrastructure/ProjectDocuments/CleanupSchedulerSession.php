<?php

declare(strict_types=1);

namespace App\Infrastructure\ProjectDocuments;

use App\Domain\Shared\ResourceOperationException;
use Throwable;

/** Recognizes recurring ticks of a live scheduler; never authorizes document deletion. */
final class CleanupSchedulerSession
{
    public const string ENVIRONMENT_VARIABLE = 'ORBIT_DOCUMENT_SCHEDULER_SESSION';

    /** @var resource|null */
    private mixed $owner = null;

    public function __construct(private readonly CleanupGate $gate) {}

    public function start(): void
    {
        if ($this->gate->status()->errorCode !== null || is_resource($this->owner)) {
            throw $this->unavailable();
        }
        $path = $this->path();
        $handle = null;
        try {
            $this->validateDirectory();
            clearstatcache(true, $path);
            if (@lstat($path) === false) {
                // Close-on-exec is essential: child ticks must not inherit the daemon's lifetime lock.
                $handle = @fopen($path, 'x+be');
                if ($handle === false || ! chmod($path, 0600)) {
                    throw $this->unavailable();
                }
            } else {
                $this->validateFile($path);
                $handle = @fopen($path, 'r+be');
            }
            if ($handle === false || ! flock($handle, LOCK_EX | LOCK_NB)) {
                throw $this->unavailable();
            }
            $this->validateOpened($path, $handle);
            $token = bin2hex(random_bytes(32));
            if (! ftruncate($handle, 0) || ! rewind($handle) || fwrite($handle, $token) !== 64 || ! fflush($handle)) {
                throw $this->unavailable();
            }
            $this->owner = $handle;
            putenv(self::ENVIRONMENT_VARIABLE.'='.$token);
            $_ENV[self::ENVIRONMENT_VARIABLE] = $token;
            $_SERVER[self::ENVIRONMENT_VARIABLE] = $token;
        } catch (Throwable) {
            if (is_resource($handle)) {
                fclose($handle);
            }
            throw $this->unavailable();
        }
    }

    public function isRecurringTick(): bool
    {
        $token = getenv(self::ENVIRONMENT_VARIABLE);
        if (! is_string($token) || preg_match('/\A[a-f0-9]{64}\z/D', $token) !== 1
            || $this->gate->status()->errorCode !== null) {
            return false;
        }
        $handle = null;
        try {
            $this->validateDirectory();
            $path = $this->path();
            $this->validateFile($path);
            $handle = @fopen($path, 'rbe');
            if ($handle === false) {
                return false;
            }
            $this->validateOpened($path, $handle);
            $blocked = 0;
            if (flock($handle, LOCK_EX | LOCK_NB, $blocked)) {
                // The former daemon died. An on-disk token alone is not proof of consumer startup.
                flock($handle, LOCK_UN);

                return false;
            }

            return $blocked === 1 && stream_get_contents($handle, 65) === $token;
        } catch (Throwable) {
            return false;
        } finally {
            if (is_resource($handle)) {
                fclose($handle);
            }
        }
    }

    public function close(): void
    {
        if (is_resource($this->owner)) {
            fclose($this->owner);
            $this->owner = null;
            putenv(self::ENVIRONMENT_VARIABLE);
            unset($_ENV[self::ENVIRONMENT_VARIABLE], $_SERVER[self::ENVIRONMENT_VARIABLE]);
        }
    }

    public function __destruct()
    {
        $this->close();
    }

    private function path(): string
    {
        return rtrim(config()->string('orbit.document_cleanup_runtime'), '/').'/scheduler.session';
    }

    private function validateDirectory(): void
    {
        $stat = @lstat(dirname($this->path()));
        if ($stat === false || $stat['uid'] !== posix_geteuid() || ($stat['mode'] & 0177777) !== 0040700) {
            throw $this->unavailable();
        }
    }

    /** @return array<string|int, int> */
    private function validateFile(string $path): array
    {
        clearstatcache(true, $path);
        $stat = @lstat($path);
        if ($stat === false || $stat['uid'] !== posix_geteuid() || ($stat['mode'] & 0177777) !== 0100600 || $stat['nlink'] !== 1) {
            throw $this->unavailable();
        }

        return $stat;
    }

    /** @param resource $handle */
    private function validateOpened(string $path, mixed $handle): void
    {
        $stat = $this->validateFile($path);
        $opened = fstat($handle);
        if ($opened === false || $opened['ino'] !== $stat['ino'] || $opened['dev'] !== $stat['dev'] || $opened['nlink'] !== 1) {
            throw $this->unavailable();
        }
    }

    private function unavailable(): ResourceOperationException
    {
        return new ResourceOperationException(CleanupGate::ERROR_CODE, 'Document cleanup state is unavailable.', 503);
    }
}
