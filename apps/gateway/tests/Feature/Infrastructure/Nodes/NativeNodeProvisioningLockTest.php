<?php

declare(strict_types=1);

use App\Domain\Nodes\NodeProvisioningLockException;
use App\Infrastructure\Nodes\NativeNodeProvisioningLock;

describe(NativeNodeProvisioningLock::class, function (): void {
    it('is reentrant and remains held until the outermost callback returns', function (): void {
        $outer = new NativeNodeProvisioningLock;
        $independent = new NativeNodeProvisioningLock;
        $nodeName = 'node-a';
        $events = [];

        $outer->run($nodeName, function () use ($outer, $independent, $nodeName, &$events): void {
            $events[] = 'outer-enter';
            $outer->run($nodeName, function () use (&$events): void {
                $events[] = 'inner-enter';
            });
            $events[] = 'inner-return';
            expect(fn () => $independent->run($nodeName, function (): void {}))
                ->toThrow(NodeProvisioningLockException::class);
            $events[] = 'outer-before-release';
        });

        expect($events)->toBe(['outer-enter', 'inner-enter', 'inner-return', 'outer-before-release']);
        expect($independent->run($nodeName, static fn (): string => 'released'))->toBe('released');
    });

    it('allows independent node names to run concurrently', function (): void {
        $first = new NativeNodeProvisioningLock;
        $second = new NativeNodeProvisioningLock;

        $first->run('node-a', function () use ($second): void {
            expect($second->run('node-b', static fn (): string => 'ok'))->toBe('ok');
        });
    });

    it('releases the lock after success and failure', function (): void {
        $lock = new NativeNodeProvisioningLock;
        $independent = new NativeNodeProvisioningLock;

        expect($lock->run('node-a', static fn (): string => 'success'))->toBe('success');
        expect($independent->run('node-a', static fn (): string => 'released'))->toBe('released');

        expect(fn () => $lock->run('node-a', static function (): never {
            throw new RuntimeException('failure');
        }))
            ->toThrow(RuntimeException::class, 'failure');
        expect($independent->run('node-a', static fn (): string => 'released-again'))->toBe('released-again');
    });
});
