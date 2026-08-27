<?php

declare(strict_types=1);

use App\Domain\Tools\ToolManagerName;
use App\Domain\Tools\ToolOperation;
use App\Domain\Tools\ToolOperationException;
use App\Infrastructure\Tools\NativeToolOperationLock;
use Illuminate\Support\Facades\Cache;

describe(NativeToolOperationLock::class, function (): void {
    it('rejects concurrent mutations for the same tool identity', function (): void {
        $lock = new NativeToolOperationLock;
        $identityKey = 'orbit:tool:7:vp:'.hash(algo: 'sha256', data: '@openai/codex');
        $identity = Cache::lock($identityKey, 3_600);

        expect($identity->get())->toBeTrue();

        try {
            expect(fn () => $lock->run(
                nodeId: 7,
                manager: ToolManagerName::Vp,
                package: '@openai/codex',
                operation: ToolOperation::Install,
                versionConstraint: '^0.150',
                callback: static fn (): bool => true,
            ))
                ->toThrow(ToolOperationException::class, 'already active');
        } finally {
            $identity->release();
        }
    });

    it('rejects concurrent mutations for the shared manager scope', function (): void {
        $lock = new NativeToolOperationLock;
        $managerKey = 'orbit:tool-manager:7:vp';
        $manager = Cache::lock($managerKey, 3_600);

        expect($manager->get())->toBeTrue();

        try {
            expect(fn () => $lock->run(
                nodeId: 7,
                manager: ToolManagerName::Vp,
                package: '@openai/codex',
                operation: ToolOperation::Install,
                versionConstraint: '^0.150',
                callback: static fn (): bool => true,
            ))
                ->toThrow(ToolOperationException::class, 'manager mutation is already active');
        } finally {
            $manager->release();
        }
    });
});
