<?php

declare(strict_types=1);

namespace App\Infrastructure\AppInstances;

use App\Domain\AppInstances\Environment\AppInstanceEnvironmentOperationLock;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Processes\CommandDeadline;
use Closure;
use LogicException;
use RuntimeException;

/** @mago-expect lint:cyclomatic-complexity,kan-defect The lock keeps ordered acquisition, bounded waiting, reentry, and cleanup in one native boundary. */
final class NativeAppInstanceEnvironmentOperationLock implements AppInstanceEnvironmentOperationLock
{
    /** @var array<int, int> */
    private array $depths = [];

    /** @var Closure(): float */
    private readonly Closure $clock;

    /** @var Closure(int): void */
    private readonly Closure $wait;

    /**
     * @param (Closure(): float)|null $clock
     * @param (Closure(int): void)|null $wait
     */
    public function __construct(
        private readonly string $directory,
        private readonly CommandDeadline $deadline,
        ?Closure $clock = null,
        ?Closure $wait = null,
    ) {
        $this->clock = $clock ?? static fn (): float => hrtime(true) / 1_000_000_000;
        $this->wait = $wait ?? static function (int $microseconds): void {
            if ($microseconds > 0) {
                usleep($microseconds);
            }
        };
    }

    public function run(array $appInstanceIds, Closure $operation): mixed
    {
        $ids = array_values(array_unique(array_map(intval(...), $appInstanceIds)));
        sort($ids, SORT_NUMERIC);

        if ($ids === []) {
            return $operation();
        }

        if (array_any($ids, static fn (int $id): bool => $id < 1)) {
            throw new LogicException('An AppInstance environment lock requires positive identifiers.');
        }

        $newIds = array_values(array_filter($ids, fn (int $id): bool => ! array_key_exists($id, $this->depths)));

        if ($this->depths !== [] && $newIds !== []) {
            throw new LogicException('A nested AppInstance environment operation cannot acquire another owner.');
        }

        if ($newIds === []) {
            foreach ($ids as $id) {
                $this->depths[$id]++;
            }

            try {
                return $operation();
            } finally {
                foreach ($ids as $id) {
                    $this->depths[$id]--;
                }
            }
        }

        $this->prepareDirectory();
        $handles = [];

        try {
            foreach ($ids as $id) {
                $path = "{$this->directory}/app-instance-{$id}.lock";
                $handle = fopen(filename: $path, mode: 'c+');

                if ($handle === false) {
                    throw new RuntimeException("Could not open AppInstance environment lock [{$path}].");
                }

                $handles[$id] = $handle;

                if (! chmod(filename: $path, permissions: 0o600)) {
                    throw new RuntimeException("Could not protect AppInstance environment lock [{$path}].");
                }

                $this->acquire($handle);
                $this->depths[$id] = 1;
            }

            return $operation();
        } finally {
            foreach (array_reverse(array_keys($handles)) as $id) {
                unset($this->depths[$id]);
                flock($handles[$id], LOCK_UN);
                fclose($handles[$id]);
            }
        }
    }

    private function prepareDirectory(): void
    {
        if ($this->directory === '') {
            throw new RuntimeException('The AppInstance environment lock directory is not configured.');
        }

        if (
            ! is_dir($this->directory)
            && ! mkdir(directory: $this->directory, permissions: 0o700, recursive: true)
            && ! is_dir($this->directory)
        ) {
            throw new RuntimeException("Could not create AppInstance environment lock directory [{$this->directory}].");
        }

        if (! chmod(filename: $this->directory, permissions: 0o700)) {
            throw new RuntimeException(
                "Could not protect AppInstance environment lock directory [{$this->directory}].",
            );
        }
    }

    /** @param resource $handle */
    private function acquire(mixed $handle): void
    {
        if (flock($handle, LOCK_EX | LOCK_NB)) {
            return;
        }

        try {
            $timeout = $this->deadline->cap(30.0);
        } catch (RuntimeException) {
            throw $this->busy();
        }

        $expiresAt = ($this->clock)() + $timeout;

        while (true) {
            $remaining = $expiresAt - ($this->clock)();

            if ($remaining <= 0.0) {
                throw $this->busy();
            }

            ($this->wait)((int) min(10_000, ceil($remaining * 1_000_000)));

            if (flock($handle, LOCK_EX | LOCK_NB)) {
                return;
            }
        }
    }

    private function busy(): ResourceOperationException
    {
        return new ResourceOperationException(
            errorCode: 'env.operation_busy',
            message: 'Another AppInstance environment operation is active. Retry the request.',
            status: 409,
        );
    }
}
