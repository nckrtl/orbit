<?php

declare(strict_types=1);

namespace App\Infrastructure\ProjectDocuments;

use App\Data\ProjectDocuments\CleanupGateStatus;
use App\Domain\Shared\ResourceOperationException;
use Closure;
use Throwable;

/** Local execution authorization only. The document cleanup worker owns durable exact-key authorization. */
final readonly class CleanupGate
{
    public const string ERROR_CODE = 'project_documents.cleanup_state_unavailable';

    public function status(): CleanupGateStatus
    {
        try {
            return $this->locked(fn (): CleanupGateStatus => $this->readStatus());
        } catch (Throwable) {
            return new CleanupGateStatus(errorCode: self::ERROR_CODE);
        }
    }

    /** Startup and pause share the same invalidation, with no database or provider access. */
    public function invalidate(): CleanupGateStatus
    {
        try {
            $this->initialize();

            return $this->locked(function (): CleanupGateStatus {
                $statePath = $this->directory().'/generation.json';
                if ($this->exists($statePath)) {
                    $this->assertLockBinding($this->readState($statePath));
                }
                $permit = $this->directory().'/permit.json';
                if ($this->exists($permit)) {
                    $this->validatePath($permit, false);
                    if (! @unlink($permit)) {
                        throw $this->unavailable();
                    }
                }
                $state = $this->directory().'/generation.json';
                if ($this->exists($state)) {
                    $this->validatePath($state, false);
                    // Losing the old binding first keeps a failed atomic write from leaving an eligible old report.
                    if (! @unlink($state)) {
                        throw $this->unavailable();
                    }
                }
                $generation = bin2hex(random_bytes(32));
                $this->writeGeneration($generation);

                return new CleanupGateStatus(generation: $generation);
            });
        } catch (Throwable) {
            throw $this->unavailable();
        }
    }

    /**
     * Hold the stable exclusive lock through the entire callback, including the provider result.
     * The callback must reload and validate durable exact-key authority before DELETE.
     * A paused gate does not execute the callback. No authorization is cached or granted here.
     *
     * @param  Closure(): void  $operation
     */
    public function executeWithPermit(Closure $operation): bool
    {
        return $this->locked(function () use ($operation): bool {
            if ($this->readStatus()->state !== 'running') {
                return false;
            }
            $operation();

            return true;
        });
    }

    /**
     * Clear earlier eligibility before scanning. The callback publishes its report before returning a binding.
     *
     * @param  Closure(string): ?array{id: string, sha256: string}  $scan
     */
    public function reconcile(Closure $scan): void
    {
        $this->locked(function () use ($scan): void {
            $state = $this->pausedState();
            $state['report_id'] = null;
            $state['report_sha256'] = null;
            $this->writeState('generation.json', $state);
            $binding = $scan($state['generation']);
            if ($binding !== null) {
                if (preg_match('/\A[a-f0-9]{64}\z/D', $binding['id']) !== 1 || preg_match('/\A[a-f0-9]{64}\z/D', $binding['sha256']) !== 1) {
                    throw $this->unavailable();
                }
                $state['report_id'] = $binding['id'];
                $state['report_sha256'] = $binding['sha256'];
                $this->writeState('generation.json', $state);
            }
        });
    }

    /** @param Closure(string, string): void $verify */
    public function resume(string $id, Closure $verify): void
    {
        $this->locked(function () use ($id, $verify): void {
            $state = $this->pausedState();
            if (preg_match('/\A[a-f0-9]{64}\z/D', $id) !== 1 || $state['report_id'] !== $id || $state['report_sha256'] === null) {
                throw new ResourceOperationException('project_documents.cleanup_report_invalid', 'Reconcile again before resuming.', 409);
            }
            $verify($state['generation'], $state['report_sha256']);
            $this->writeState('permit.json', $state);
        });
    }

    /** @return array{schema_version: int, generation: string, report_id: ?string, report_sha256: ?string, lock_device: int, lock_inode: int} */
    private function pausedState(): array
    {
        if ($this->readStatus()->state !== 'paused') {
            throw new ResourceOperationException('project_documents.cleanup_not_paused', 'Pause cleanup before recovery.', 409);
        }

        return $this->readState($this->directory().'/generation.json');
    }

    private function directory(): string
    {
        return rtrim(config()->string('orbit.document_cleanup_runtime'), '/');
    }

    private function initialize(): void
    {
        $directory = $this->directory();
        $this->validateAncestors();
        $created = false;
        if (! $this->exists($directory)) {
            $created = @mkdir($directory, 0700);
            if (! $created) {
                throw $this->unavailable();
            }
        }
        $this->validatePath($directory, true);
        $lock = $directory.'/execution.lock';
        if (! $this->exists($lock)) {
            // Only the process creating the directory may create its first lock.
            // Existing directories with missing locks require repair with all services stopped.
            if (! $created) {
                throw $this->unavailable();
            }
            $handle = @fopen($lock, 'x+b');
            if ($handle !== false) {
                try {
                    if (! @chmod($lock, 0600)) {
                        throw $this->unavailable();
                    }
                } finally {
                    fclose($handle);
                }
            }
            $this->validatePath($lock, false);
        }
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $operation
     * @return T
     */
    private function locked(Closure $operation): mixed
    {
        $this->validateAncestors();
        $this->validatePath($this->directory(), true);
        $path = $this->directory().'/execution.lock';
        $before = $this->validatePath($path, false);
        $handle = @fopen($path, 'r+b');
        if ($handle === false) {
            throw $this->unavailable();
        }
        try {
            if (! @flock($handle, LOCK_EX)) {
                throw $this->unavailable();
            }
            $after = $this->validatePath($path, false);
            $opened = fstat($handle);
            if ($opened === false || $before['ino'] !== $after['ino'] || $opened['ino'] !== $after['ino']
                || $before['dev'] !== $after['dev'] || $opened['dev'] !== $after['dev'] || $opened['nlink'] !== 1) {
                throw $this->unavailable();
            }
            $this->validatePath($this->directory(), true);

            return $operation();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    private function readStatus(): CleanupGateStatus
    {
        $state = $this->readState($this->directory().'/generation.json');
        $this->assertLockBinding($state);
        $permitPath = $this->directory().'/permit.json';
        if (! $this->exists($permitPath)) {
            return new CleanupGateStatus(generation: $state['generation'], reportId: $state['report_id']);
        }
        $permit = $this->readState($permitPath);
        if ($state['report_id'] === null || $state['report_sha256'] === null || $permit !== $state) {
            throw $this->unavailable();
        }

        return new CleanupGateStatus('running', $state['generation'], $state['report_id']);
    }

    /** @return array{schema_version: int, generation: string, report_id: ?string, report_sha256: ?string, lock_device: int, lock_inode: int} */
    private function readState(string $path): array
    {
        $this->validatePath($path, false);
        $bytes = @file_get_contents($path, length: 8193);
        if ($bytes === false || strlen($bytes) > 8192) {
            throw $this->unavailable();
        }
        try {
            $state = json_decode($bytes, true, 8, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            throw $this->unavailable();
        }
        if (! is_array($state) || count($state) !== 6 || ($state['schema_version'] ?? null) !== 1
            || ! is_int($state['lock_device'] ?? null) || $state['lock_device'] < 0
            || ! is_int($state['lock_inode'] ?? null) || $state['lock_inode'] <= 0
            || ! $this->identifier($state['generation'] ?? null)
            || ! array_key_exists('report_id', $state) || ! array_key_exists('report_sha256', $state)
            || ($state['report_id'] !== null && ! $this->identifier($state['report_id']))
            || ($state['report_sha256'] !== null && ! $this->identifier($state['report_sha256']))
            || (($state['report_id'] === null) !== ($state['report_sha256'] === null))) {
            throw $this->unavailable();
        }

        return ['schema_version' => 1, 'generation' => $state['generation'], 'report_id' => $state['report_id'], 'report_sha256' => $state['report_sha256'], 'lock_device' => $state['lock_device'], 'lock_inode' => $state['lock_inode']];
    }

    /** @param array{lock_device: int, lock_inode: int} $state */
    private function assertLockBinding(array $state): void
    {
        $lock = $this->validatePath($this->directory().'/execution.lock', false);
        if ($state['lock_device'] !== $lock['dev'] || $state['lock_inode'] !== $lock['ino']) {
            throw $this->unavailable();
        }
    }

    /** @phpstan-assert-if-true string $value */
    private function identifier(mixed $value): bool
    {
        return is_string($value) && preg_match('/\A[a-f0-9]{64}\z/D', $value) === 1;
    }

    private function writeGeneration(string $generation): void
    {
        $lock = $this->validatePath($this->directory().'/execution.lock', false);
        $this->writeState('generation.json', ['schema_version' => 1, 'generation' => $generation, 'report_id' => null, 'report_sha256' => null, 'lock_device' => $lock['dev'], 'lock_inode' => $lock['ino']]);
    }

    /** @param array<string, int|string|null> $state */
    private function writeState(string $name, array $state): void
    {
        $path = $this->directory().'/'.$name;
        if ($this->exists($path)) {
            $this->validatePath($path, false);
        }
        $temporary = $this->directory().'/.generation-'.bin2hex(random_bytes(16));
        $handle = @fopen($temporary, 'x+b');
        if ($handle === false) {
            throw $this->unavailable();
        }
        try {
            $bytes = json_encode($state, JSON_THROW_ON_ERROR);
            if (! @chmod($temporary, 0600) || @fwrite($handle, $bytes) !== strlen($bytes)
                || ! @fflush($handle) || ! @fsync($handle) || ! @rename($temporary, $path)) {
                throw $this->unavailable();
            }
        } finally {
            fclose($handle);
            if ($this->exists($temporary)) {
                @unlink($temporary);
            }
        }
    }

    /** @return array<string|int, int> */
    private function validatePath(string $path, bool $directory): array
    {
        clearstatcache(true, $path);
        $stat = @lstat($path);
        if ($stat === false || $stat['uid'] !== posix_geteuid()
            || ($stat['mode'] & 0170000) !== ($directory ? 0040000 : 0100000)
            || ($stat['mode'] & 07777) !== ($directory ? 0700 : 0600)
            || (! $directory && $stat['nlink'] !== 1)) {
            throw $this->unavailable();
        }

        return $stat;
    }

    private function validateAncestors(): void
    {
        $directory = $this->directory();
        if (! str_starts_with($directory, '/') || str_contains($directory, '/../') || str_contains($directory, '/./')) {
            throw $this->unavailable();
        }
        for ($parent = dirname($directory); ; $parent = dirname($parent)) {
            clearstatcache(true, $parent);
            $stat = @lstat($parent);
            if ($stat === false || ($stat['mode'] & 0170000) !== 0040000
                || ! in_array($stat['uid'], [0, posix_geteuid()], true)
                || (($stat['mode'] & 0022) !== 0 && ($stat['mode'] & 01000) === 0)) {
                throw $this->unavailable();
            }
            if ($parent === '/') {
                break;
            }
        }
    }

    private function exists(string $path): bool
    {
        clearstatcache(true, $path);

        return @lstat($path) !== false;
    }

    private function unavailable(): ResourceOperationException
    {
        return new ResourceOperationException(self::ERROR_CODE, 'Document cleanup state is unavailable.', 503);
    }
}
