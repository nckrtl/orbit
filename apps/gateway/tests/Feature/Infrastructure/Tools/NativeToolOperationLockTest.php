<?php

declare(strict_types=1);

use App\Domain\Tools\ToolManagerName;
use App\Domain\Tools\ToolManagerScopeLockException;
use App\Domain\Tools\ToolOperation;
use App\Domain\Tools\ToolOperationException;
use App\Infrastructure\Nodes\NodeLocks;
use App\Infrastructure\Tools\NativeToolManagerScopeLock;
use App\Infrastructure\Tools\NativeToolOperationLock;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Cache;

describe(NativeToolOperationLock::class, function (): void {
    it('releases the shared manager scope when its callback fails', function (): void {
        $scope = new NativeToolManagerScopeLock;

        expect(fn () => $scope->run(
            nodeId: 7,
            manager: ToolManagerName::Vp,
            callback: static fn () => throw new RuntimeException('callback failed'),
        ))
            ->toThrow(RuntimeException::class, 'callback failed');

        $manager = Cache::lock('orbit:tool-manager:7:vp', 3_600);
        expect($manager->get())->toBeTrue();
        $manager->release();
    });
    it('rejects concurrent mutations for the same tool identity', function (): void {
        $lock = new NativeToolOperationLock(new NativeToolManagerScopeLock);
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
        $lock = new NativeToolOperationLock(new NativeToolManagerScopeLock);
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

    it('keeps manager and tool locks in the pinned Node lock store with the request term', function (): void {
        $home = sys_get_temp_dir().'/orbit-tool-locks-'.bin2hex(random_bytes(4));
        config(['orbit.home' => $home, 'cache.default' => 'database']);
        app()->forgetInstance(NodeLocks::class);

        try {
            $scope = new NativeToolManagerScopeLock;
            $lock = new NativeToolOperationLock($scope);

            $lock->run(7, ToolManagerName::Vp, '@openai/codex', ToolOperation::Install, null, function () use ($home): void {
                expect(app(NodeLocks::class)->lock('tool-manager:7:vp', 5)->get())->toBeFalse()
                    ->and(app(NodeLocks::class)->lock('tool:7:vp:'.hash('sha256', '@openai/codex'), 5)->get())->toBeFalse()
                    ->and(glob($home.'/cache/node-locks/*/*/*') ?: [])->not->toBe([]);
            });

            expect(app(NodeLocks::class)->lock('tool-manager:7:vp', 5)->get())->toBeTrue();
        } finally {
            (new Filesystem)->deleteDirectory($home);
        }
    });

    it('frees a manager scope that a killed worker left behind once the request term passed', function (): void {
        $abandoned = app(NodeLocks::class)->lock('tool-manager:7:composer', NodeLocks::RequestSeconds);
        expect($abandoned->get())->toBeTrue();

        expect(fn () => (new NativeToolManagerScopeLock)->run(7, ToolManagerName::Composer, static fn (): null => null))
            ->toThrow(ToolManagerScopeLockException::class);

        $this->travel(NodeLocks::RequestSeconds + 1)->seconds();

        expect((new NativeToolManagerScopeLock)->run(7, ToolManagerName::Composer, static fn (): string => 'free'))->toBe('free');
    });
});
