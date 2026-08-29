<?php

declare(strict_types=1);

use App\E2E\State\OperationLock;
use App\E2E\State\StatePaths;
use App\E2E\Value\OperationId;

describe('OperationLock', function () {
    it('allows shared generation pins and bounds an exclusive waiter', function () {
        $paths = new StatePaths(temporaryPath('orbit-lock-', 4));
        $first = new OperationLock($paths);
        $second = new OperationLock($paths);
        $refresh = new OperationLock($paths);

        expect($first->acquire('generation-g1', new OperationId(str_repeat('a', 32)), false, 0.05))
            ->toBeTrue()
            ->and($second->acquire('generation-g1', new OperationId(str_repeat('b', 32)), false, 0.05))
            ->toBeTrue();

        $started = microtime(true);
        expect($refresh->acquire('generation-g1', new OperationId(str_repeat('c', 32)), true, 0.05))
            ->toBeFalse()
            ->and(microtime(true) - $started)
            ->toBeGreaterThanOrEqual(0.04);

        $first->release();
        $second->release();
    });

    it('persists caller ownership and verifies stale process identity', function () {
        $paths = new StatePaths(temporaryPath('orbit-lock-', 4));
        $lock = new OperationLock($paths);
        $operation = new OperationId(str_repeat('d', 32));
        $lock->acquire('feature-NCK-321', $operation, true, 0.05);
        $owner = json_decode(
            (string) file_get_contents($paths->path('locks/feature-NCK-321.lock')),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        expect($owner)
            ->toHaveKeys(['pid', 'process_start_identity', 'operation_id', 'acquired_at'])
            ->and($owner['operation_id'])
            ->toBe($operation->value)
            ->and(OperationLock::isStale($owner))
            ->toBeFalse()
            ->and(OperationLock::isStale([...$owner, 'process_start_identity' => 'wrong']))
            ->toBeTrue()
            ->and(OperationLock::isStale([
                'pid' => 999_999_999,
                'process_start_identity' => '1',
                'operation_id' => str_repeat('e', 32),
                'acquired_at' => '2026-08-28T00:00:00Z',
            ]))
            ->toBeTrue();

        $lock->release();
    });

    it('clears only an exact stale owner when no process holds the lock', function () {
        $paths = new StatePaths(temporaryPath('orbit-lock-', 4));
        $file = $paths->ensureParent('locks/stale.lock');
        $owner = [
            'pid' => 999_999_999,
            'process_start_identity' => '1',
            'operation_id' => str_repeat('f', 32),
            'acquired_at' => '2026-08-28T00:00:00Z',
        ];
        file_put_contents($file, json_encode($owner, JSON_THROW_ON_ERROR)."\n");
        $lock = new OperationLock($paths);

        expect($lock->clearStaleOwner('stale', [...$owner, 'operation_id' => 'different']))
            ->toBeFalse()
            ->and($lock->clearStaleOwner('stale', $owner))
            ->toBeTrue()
            ->and(file_get_contents($file))
            ->toBe('');
    });

    it('uses an injected process identity when proc is unavailable', function () {
        $paths = new StatePaths(temporaryPath('orbit-lock-', 4));
        $lock = new OperationLock($paths, fn (int $pid): string => 'portable-start-'.$pid);

        expect($lock->acquire('portable', new OperationId(str_repeat('1', 32)), true, 0.05))->toBeTrue();
        $owner = json_decode(
            (string) file_get_contents($paths->path('locks/portable.lock')),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        expect($owner['process_start_identity'])
            ->toBe('portable-start-'.getmypid())
            ->and(OperationLock::isStale($owner, fn (int $pid): string => 'portable-start-'.$pid))
            ->toBeFalse();

        $lock->release();
    });

    it('does not leak a lock when process identity resolution fails', function () {
        $paths = new StatePaths(temporaryPath('orbit-lock-', 4));
        $failing = new OperationLock($paths, fn (): null => null);

        expect(fn () => $failing->acquire('recoverable', new OperationId(str_repeat('2', 32)), true, 0.05))
            ->toThrow(RuntimeException::class, 'process start');

        $next = new OperationLock($paths, fn (int $pid): string => 'start-'.$pid);
        expect($next->acquire('recoverable', new OperationId(str_repeat('3', 32)), true, 0.05))->toBeTrue();
        $next->release();
    });

    it('closes stale-cleanup handles when another operation holds the lock', function () {
        $paths = new StatePaths(temporaryPath('orbit-lock-', 4));
        $holder = new OperationLock($paths, fn (int $pid): string => 'start-'.$pid);
        $holder->acquire('contended', new OperationId(str_repeat('4', 32)), true, 0.05);
        $staleOwner = [
            'pid' => 999_999_999,
            'process_start_identity' => '1',
            'operation_id' => str_repeat('5', 32),
            'acquired_at' => '2026-08-28T00:00:00Z',
        ];
        $cleaner = new OperationLock($paths);
        $descriptorDirectory = '/proc/self/fd';
        $before = is_dir($descriptorDirectory) ? count(scandir($descriptorDirectory)) : null;

        foreach (range(1, 10) as $_) {
            expect($cleaner->clearStaleOwner('contended', $staleOwner))->toBeFalse();
        }

        $after = is_dir($descriptorDirectory) ? count(scandir($descriptorDirectory)) : null;
        expect($after)->toBe($before);
        $holder->release();
    });
});
