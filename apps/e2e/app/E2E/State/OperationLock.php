<?php

declare(strict_types=1);

namespace App\E2E\State;

use App\E2E\Value\OperationId;
use Closure;
use RuntimeException;
use Throwable;

/** @mago-expect lint:cyclomatic-complexity,kan-defect Lock ownership requires explicit failure checks. */
final class OperationLock
{
    /** @var resource|null */
    private $handle = null;

    private ?array $owner = null;

    public function __construct(
        private readonly StatePaths $paths,
        private readonly ?Closure $processIdentityResolver = null,
    ) {}

    public function acquire(
        string $name,
        OperationId $operationId,
        bool $exclusive = true,
        float $timeoutSeconds = 5.0,
    ): bool {
        if (
            $this->handle !== null
            || preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]{0,127}\z/D', $name) !== 1
            || $timeoutSeconds < 0
        ) {
            throw new RuntimeException('The lock request is invalid.');
        }

        $file = $this->paths->ensureParent('locks/'.$name.'.lock');
        $handle = fopen($file, 'c+b');

        if ($handle === false) {
            throw new RuntimeException('Unable to open the operation lock.');
        }

        chmod($file, 0600);
        $deadline = hrtime(true) + (int) ($timeoutSeconds * 1_000_000_000);
        $mode = ($exclusive ? LOCK_EX : LOCK_SH) | LOCK_NB;

        do {
            if (flock($handle, $mode)) {
                $this->handle = $handle;

                try {
                    if ($exclusive) {
                        $owner = self::currentOwner($operationId, $this->processIdentityResolver);
                        $this->owner = $owner;
                        $this->writeOwner($owner);
                    } else {
                        $this->owner = null;
                    }
                } catch (Throwable $exception) {
                    flock($handle, LOCK_UN);
                    fclose($handle);
                    $this->handle = null;
                    $this->owner = null;

                    throw $exception;
                }

                return true;
            }

            usleep(10_000);
        } while (hrtime(true) < $deadline);

        fclose($handle);

        return false;
    }

    public function release(): void
    {
        if (! is_resource($this->handle)) {
            return;
        }

        $ownerMatches = true;

        if ($this->owner !== null) {
            rewind($this->handle);
            $persisted = stream_get_contents($this->handle);
            $decoded = json_decode($persisted === false ? '' : $persisted, true);

            $ownerMatches = $decoded === $this->owner;

            if ($ownerMatches) {
                ftruncate($this->handle, 0);
                fflush($this->handle);
            }
        }

        flock($this->handle, LOCK_UN);
        fclose($this->handle);
        $this->handle = null;
        $this->owner = null;

        if (! $ownerMatches) {
            throw new RuntimeException('The lock owner changed before release.');
        }
    }

    /** @param array<string, mixed> $expectedOwner */
    public function clearStaleOwner(string $name, array $expectedOwner): bool
    {
        if (preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]{0,127}\z/D', $name) !== 1 || ! self::isStale($expectedOwner)) {
            return false;
        }

        $file = $this->paths->path('locks/'.$name.'.lock');
        $handle = fopen($file, 'c+b');

        if ($handle === false) {
            return false;
        }

        $locked = false;

        try {
            $locked = flock($handle, LOCK_EX | LOCK_NB);

            if (! $locked) {
                return false;
            }

            rewind($handle);
            $contents = stream_get_contents($handle);
            $persisted = json_decode($contents === false ? '' : $contents, true);

            if ($persisted !== $expectedOwner) {
                return false;
            }

            ftruncate($handle, 0);
            fflush($handle);

            return true;
        } finally {
            if ($locked) {
                flock($handle, LOCK_UN);
            }

            fclose($handle);
        }
    }

    /** @return array{pid:int,process_start_identity:string,operation_id:string,acquired_at:string} */
    public static function currentOwner(OperationId $operationId, ?Closure $resolver = null): array
    {
        $pid = getmypid();

        if ($pid === false) {
            throw new RuntimeException('Unable to identify the lock process.');
        }

        $identity = self::processStartIdentity($pid, $resolver);

        if ($identity === null) {
            throw new RuntimeException('Unable to identify the lock process start.');
        }

        return [
            'pid' => $pid,
            'process_start_identity' => $identity,
            'operation_id' => $operationId->value,
            'acquired_at' => gmdate('Y-m-d\TH:i:s\Z'),
        ];
    }

    /** @param array<string, mixed> $owner */
    public static function isStale(array $owner, ?Closure $resolver = null): bool
    {
        if (
            array_keys($owner) !== ['pid', 'process_start_identity', 'operation_id', 'acquired_at']
            || ! is_int($owner['pid'])
            || ! is_string($owner['process_start_identity'])
            || ! is_string($owner['operation_id'])
            || preg_match('/\A[a-f0-9]{32}\z/D', $owner['operation_id']) !== 1
            || ! is_string($owner['acquired_at'])
            || strtotime($owner['acquired_at']) === false
        ) {
            return false;
        }

        $identity = self::processStartIdentity($owner['pid'], $resolver);

        return $identity === null || ! hash_equals($owner['process_start_identity'], $identity);
    }

    public function __destruct()
    {
        if (is_resource($this->handle)) {
            flock($this->handle, LOCK_UN);
            fclose($this->handle);
        }
    }

    /** @param array<string, mixed> $owner */
    private function writeOwner(array $owner): void
    {
        $handle = $this->handle;

        if (! is_resource($handle)) {
            throw new RuntimeException('The operation lock is not held.');
        }

        $json = json_encode($owner, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n";
        rewind($handle);
        ftruncate($handle, 0);

        if (
            fwrite($handle, $json) !== strlen($json)
            || ! fflush($handle)
            || function_exists('fsync')
            && ! fsync($handle)
        ) {
            throw new RuntimeException('Unable to write lock ownership.');
        }
    }

    private static function processStartIdentity(int $pid, ?Closure $resolver = null): ?string
    {
        if ($resolver !== null) {
            $identity = $resolver($pid);

            return is_string($identity) && $identity !== '' ? $identity : null;
        }

        $path = '/proc/'.$pid.'/stat';

        if (! is_file($path)) {
            return null;
        }

        $stat = file_get_contents($path);

        if ($stat === false) {
            return null;
        }

        $closingParenthesis = strrpos($stat, ')');
        $fields = $closingParenthesis === false
            ? []
            : preg_split('/\s+/', trim(substr($stat, $closingParenthesis + 1)));

        return is_array($fields) && isset($fields[19]) ? $fields[19] : null;
    }
}
