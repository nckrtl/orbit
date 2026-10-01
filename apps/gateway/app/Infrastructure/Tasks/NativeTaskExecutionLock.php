<?php

declare(strict_types=1);

namespace App\Infrastructure\Tasks;

use App\Domain\Tasks\TaskExecutionLock;
use Closure;
use RuntimeException;

/** A non-expiring, process-owned Gateway lock. A crash releases it, but never removes the lock file. */
final class NativeTaskExecutionLock implements TaskExecutionLock
{
    /** @var array<int, true> */
    private array $held = [];

    public function __construct(private readonly string $directory) {}

    public function synchronized(int $groupId, Closure $operation): mixed
    {
        if (isset($this->held[$groupId])) {
            return $operation();
        }
        if (! is_dir($this->directory) && ! mkdir($this->directory, 0o700, true) && ! is_dir($this->directory)) {
            throw new RuntimeException('Could not create task execution lock directory.');
        }
        $path = $this->directory.'/task-'.$groupId.'.lock';
        $handle = fopen($path, 'c+');
        if ($handle === false) {
            throw new RuntimeException('Could not open task execution lock.');
        }
        try {
            if (! chmod($this->directory, 0o700) || ! chmod($path, 0o600) || ! flock($handle, LOCK_EX)) {
                throw new RuntimeException('Could not acquire task execution lock.');
            }
            $this->held[$groupId] = true;

            return $operation();
        } finally {
            unset($this->held[$groupId]);
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
}
