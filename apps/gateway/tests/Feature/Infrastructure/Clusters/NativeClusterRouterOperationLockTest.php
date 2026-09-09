<?php

declare(strict_types=1);

use App\Domain\Clusters\ClusterRouterOperationLock;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Clusters\NativeClusterRouterOperationLock;
use App\Infrastructure\Processes\CommandDeadline;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

describe(NativeClusterRouterOperationLock::class, function (): void {
    it('keeps one same-Cluster owner through reentry and releases it after failure', function (): void {
        $directory = sys_get_temp_dir().'/orbit-cluster-router-'.Str::uuid();
        $owner = new NativeClusterRouterOperationLock($directory, new CommandDeadline);
        $contender = new NativeClusterRouterOperationLock($directory, new CommandDeadline);
        $events = [];

        try {
            $run = function () use ($owner, $contender, $directory, &$events): void {
                $owner->run(17, function () use ($owner, $contender, $directory, &$events): never {
                    $events[] = 'outer-enter';
                    $owner->run(17, function () use ($contender, $directory, &$events): void {
                        $events[] = 'inner-enter';
                        $path = $directory.'/cluster-17.lock';
                        $handle = fopen($path, mode: 'c+');
                        expect($handle)->not->toBeFalse();

                        try {
                            expect(flock($handle, LOCK_EX | LOCK_NB))->toBeFalse();
                        } finally {
                            fclose($handle);
                        }

                        expect($contender->run(18, static fn (): string => 'independent'))
                            ->toBe('independent');
                    });
                    $events[] = 'inner-return';

                    throw new RuntimeException('outer failure');
                });
            };

            expect($run)
                ->toThrow(RuntimeException::class, 'outer failure');

            expect($events)
                ->toBe(['outer-enter', 'inner-enter', 'inner-return'])
                ->and($contender->run(17, static fn (): string => 'released'))
                ->toBe('released')
                ->and(fileperms($directory) & 0o777)
                ->toBe(0o700)
                ->and(fileperms($directory.'/cluster-17.lock') & 0o777)
                ->toBe(0o600);
        } finally {
            new Filesystem()->deleteDirectory($directory);
        }
    });

    it('refuses nested acquisition of another Cluster through the same request owner', function (): void {
        $directory = sys_get_temp_dir().'/orbit-cluster-router-order-'.Str::uuid();
        $owner = new NativeClusterRouterOperationLock($directory, new CommandDeadline);

        try {
            expect(fn () => $owner->run(17, fn (): mixed => $owner->run(18, static fn (): string => 'nested')))
                ->toThrow(LogicException::class, 'cannot acquire a second Cluster owner');
        } finally {
            new Filesystem()->deleteDirectory($directory);
        }
    });

    it('waits within the command deadline and enters after release', function (): void {
        $directory = sys_get_temp_dir().'/orbit-cluster-router-wait-'.Str::uuid();
        mkdir($directory, permissions: 0o700, recursive: true);
        $held = fopen($directory.'/cluster-23.lock', mode: 'c+');
        expect($held)->not->toBeFalse();
        flock($held, LOCK_EX);
        $now = 10.0;
        $clock = static function () use (&$now): float {
            return $now;
        };
        $deadline = new CommandDeadline($clock);
        $deadline->start(0.025);
        $waits = 0;
        $owner = new NativeClusterRouterOperationLock(
            $directory,
            $deadline,
            $clock,
            static function (int $microseconds) use (&$now, &$waits, &$held): void {
                $now += $microseconds / 1_000_000;
                $waits++;

                if ($waits === 2 && is_resource($held)) {
                    flock($held, LOCK_UN);
                    fclose($held);
                    $held = null;
                }
            },
        );

        try {
            expect($owner->run(23, static fn (): string => 'fresh'))
                ->toBe('fresh')
                ->and($waits)
                ->toBe(2)
                ->and(round($now, 3))
                ->toBe(10.02);
        } finally {
            if (is_resource($held)) {
                flock($held, LOCK_UN);
                fclose($held);
            }

            new Filesystem()->deleteDirectory($directory);
        }
    });

    it('returns the bounded retryable conflict without stealing a same-Cluster owner', function (): void {
        $directory = sys_get_temp_dir().'/orbit-cluster-router-busy-'.Str::uuid();
        mkdir($directory, permissions: 0o700, recursive: true);
        $held = fopen($directory.'/cluster-31.lock', mode: 'c+');
        expect($held)->not->toBeFalse();
        flock($held, LOCK_EX);
        $now = 0.0;
        $clock = static function () use (&$now): float {
            return $now;
        };
        $owner = new NativeClusterRouterOperationLock(
            $directory,
            new CommandDeadline($clock),
            $clock,
            static function (int $microseconds) use (&$now): void {
                $now += $microseconds / 1_000_000;
            },
        );
        $entered = false;

        try {
            expect(fn () => $owner->run(31, function () use (&$entered): void {
                $entered = true;
            }))
                ->toThrow(function (ResourceOperationException $exception): void {
                    expect($exception->errorCode)
                        ->toBe('cluster.router_busy')
                        ->and($exception->status)
                        ->toBe(409)
                        ->and($exception->getMessage())
                        ->toBe('Another Cluster Router operation is active. Retry the request.');
                });

            expect($entered)
                ->toBeFalse()
                ->and(round($now, 3))
                ->toBe(30.0)
                ->and(flock($held, LOCK_EX | LOCK_NB))
                ->toBeTrue();
        } finally {
            flock($held, LOCK_UN);
            fclose($held);
            new Filesystem()->deleteDirectory($directory);
        }
    });

    it('releases ownership when the owning process exits', function (): void {
        $directory = sys_get_temp_dir().'/orbit-cluster-router-process-'.Str::uuid();
        $autoload = base_path('vendor/autoload.php');
        $script = <<<'PHP'
require $argv[1];
$owner = new App\Infrastructure\Clusters\NativeClusterRouterOperationLock(
    $argv[2],
    new App\Infrastructure\Processes\CommandDeadline,
);
$owner->run(41, static function (): never {
    exit(0);
});
PHP;
        $process = new Process([PHP_BINARY, '-r', $script, $autoload, $directory]);

        try {
            $process->mustRun();
            $next = new NativeClusterRouterOperationLock($directory, new CommandDeadline);

            expect($next->run(41, static fn (): string => 'released'))->toBe('released');
        } finally {
            new Filesystem()->deleteDirectory($directory);
        }
    });

    it('binds one native owner for each application request scope', function (): void {
        $first = app(ClusterRouterOperationLock::class);
        $sameScope = app(ClusterRouterOperationLock::class);

        app()->forgetScopedInstances();

        $nextScope = app(ClusterRouterOperationLock::class);

        expect($first)
            ->toBeInstanceOf(NativeClusterRouterOperationLock::class)
            ->toBe($sameScope)
            ->not->toBe($nextScope);
    });
});
