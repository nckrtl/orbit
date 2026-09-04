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

    it('persists caller ownership in the lock file', function () {
        $paths = new StatePaths(temporaryPath('orbit-lock-', 4));
        $lock = new OperationLock($paths);
        $operation = new OperationId(str_repeat('d', 32));
        $lock->acquire('feature-TST-321', $operation, true, 0.05);
        $owner = json_decode(
            (string) file_get_contents($paths->path('locks/feature-TST-321.lock')),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        expect($owner)
            ->toHaveKeys(['pid', 'process_start_identity', 'operation_id', 'acquired_at'])
            ->and($owner['operation_id'])
            ->toBe($operation->value)
            ->and($owner['pid'])
            ->toBe(getmypid());

        $lock->release();
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
        expect($owner['process_start_identity'])->toBe('portable-start-'.getmypid());

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
});
