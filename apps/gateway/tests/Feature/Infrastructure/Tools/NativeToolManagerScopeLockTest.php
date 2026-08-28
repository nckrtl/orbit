<?php

declare(strict_types=1);

use App\Domain\Tools\ToolManagerName;
use App\Domain\Tools\ToolManagerScopeLockException;
use App\Infrastructure\Tools\NativeToolManagerScopeLock;

describe(NativeToolManagerScopeLock::class, function (): void {
    it('is reentrant and remains held until the outermost callback returns', function (): void {
        $outer = new NativeToolManagerScopeLock;
        $independent = new NativeToolManagerScopeLock;
        $nodeId = 991;
        $manager = ToolManagerName::Vp;
        $events = [];

        $outer->run($nodeId, $manager, function () use ($outer, $independent, $nodeId, $manager, &$events): void {
            $events[] = 'outer-enter';
            $outer->run($nodeId, $manager, function () use (&$events): void {
                $events[] = 'inner-enter';
            });
            $events[] = 'inner-return';
            expect(fn () => $independent->run($nodeId, $manager, function (): void {}))
                ->toThrow(ToolManagerScopeLockException::class);
            $events[] = 'outer-before-release';
        });

        expect($events)->toBe(['outer-enter', 'inner-enter', 'inner-return', 'outer-before-release']);
        expect($independent->run($nodeId, $manager, static fn (): string => 'released'))->toBe('released');
    });
});
