<?php

declare(strict_types=1);

use App\Domain\AppInstances\Environment\AppInstanceEnvironmentOperationLock;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AppInstances\NativeAppInstanceEnvironmentOperationLock;
use App\Infrastructure\Processes\CommandDeadline;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;

describe(NativeAppInstanceEnvironmentOperationLock::class, function (): void {
    it('acquires multiple owners in identifier order and supports same-set reentry', function (): void {
        $directory = sys_get_temp_dir().'/orbit-environment-lock-'.Str::uuid();
        $owner = new NativeAppInstanceEnvironmentOperationLock($directory, new CommandDeadline);

        try {
            $result = $owner->run([22, 11, 22], function () use ($owner, $directory): string {
                $contender = fopen("{$directory}/app-instance-11.lock", 'c+');
                expect($contender)
                    ->not
                    ->toBeFalse()
                    ->and(flock($contender, LOCK_EX | LOCK_NB))
                    ->toBeFalse();
                fclose($contender);

                return $owner->run([11, 22], static fn (): string => 'reentered');
            });

            expect($result)
                ->toBe('reentered')
                ->and(fileperms($directory) & 0o777)
                ->toBe(0o700)
                ->and(fileperms("{$directory}/app-instance-11.lock") & 0o777)
                ->toBe(0o600);
        } finally {
            new Filesystem()->deleteDirectory($directory);
        }
    });

    it('returns a bounded value-free conflict and releases an earlier partial acquisition', function (): void {
        $directory = sys_get_temp_dir().'/orbit-environment-lock-busy-'.Str::uuid();
        mkdir($directory, permissions: 0o700, recursive: true);
        $held = fopen("{$directory}/app-instance-22.lock", 'c+');
        expect($held)->not->toBeFalse();
        flock($held, LOCK_EX);
        $now = 0.0;
        $clock = static function () use (&$now): float {
            return $now;
        };
        $owner = new NativeAppInstanceEnvironmentOperationLock(
            $directory,
            new CommandDeadline($clock),
            $clock,
            static function (int $microseconds) use (&$now): void {
                $now += $microseconds / 1_000_000;
            },
        );

        try {
            expect(fn () => $owner->run([11, 22], static fn (): string => 'unexpected'))
                ->toThrow(function (ResourceOperationException $exception) use ($directory): void {
                    expect($exception->errorCode)
                        ->toBe('env.operation_busy')
                        ->and($exception->status)
                        ->toBe(409)
                        ->and($exception->getMessage())
                        ->toBe('Another AppInstance environment operation is active. Retry the request.')
                        ->not->toContain($directory);
                });

            $earlier = fopen("{$directory}/app-instance-11.lock", 'c+');
            expect($earlier)
                ->not
                ->toBeFalse()
                ->and(round($now, 3))
                ->toBe(30.0)
                ->and(flock($earlier, LOCK_EX | LOCK_NB))
                ->toBeTrue();
            flock($earlier, LOCK_UN);
            fclose($earlier);
        } finally {
            flock($held, LOCK_UN);
            fclose($held);
            new Filesystem()->deleteDirectory($directory);
        }
    });

    it('binds one owner per application request scope', function (): void {
        $first = app(AppInstanceEnvironmentOperationLock::class);
        $sameScope = app(AppInstanceEnvironmentOperationLock::class);

        app()->forgetScopedInstances();
        $nextScope = app(AppInstanceEnvironmentOperationLock::class);

        expect($first)
            ->toBeInstanceOf(NativeAppInstanceEnvironmentOperationLock::class)
            ->toBe($sameScope)
            ->not->toBe($nextScope);
    });
});
