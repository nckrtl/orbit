<?php

declare(strict_types=1);

namespace App\Infrastructure\Tasks;

use App\Domain\Tasks\TaskStartupLock;
use App\Models\TaskGroup;
use Closure;
use RuntimeException;

final readonly class NativeTaskStartupLock implements TaskStartupLock
{
    public function __construct(private string $directory) {}

    public function run(Closure $operation): ?TaskGroup
    {
        if (! is_dir($this->directory) && ! mkdir($this->directory, 0o700, true) && ! is_dir($this->directory)) {
            throw new RuntimeException('Task startup lock directory is unavailable.');
        }
        $handle = fopen($this->directory.'/startup.lock', 'ce');
        if ($handle === false) {
            throw new RuntimeException('Task startup lock is unavailable.');
        }
        try {
            chmod($this->directory.'/startup.lock', 0o600);
            if (! flock($handle, LOCK_EX | LOCK_NB)) {
                return null;
            }

            return $operation();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
}
