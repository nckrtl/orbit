<?php

declare(strict_types=1);

use App\Domain\Metrics\MetricsCredentialOperationLock;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Metrics\NativeMetricsCredentialOperationLock;
use App\Infrastructure\Processes\CommandDeadline;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

describe(NativeMetricsCredentialOperationLock::class, function (): void {
    it('excludes another process for one Node without blocking another Node', function (): void {
        $directory = sys_get_temp_dir().'/orbit-metrics-credential-process-'.Str::uuid();
        $ready = "{$directory}.ready";
        $autoload = base_path('vendor/autoload.php');
        $script = <<<'PHP'
            require $argv[1];
            $owner = new App\Infrastructure\Metrics\NativeMetricsCredentialOperationLock(
                $argv[2],
                new App\Infrastructure\Processes\CommandDeadline,
            );
            $owner->run(41, static function () use ($argv): void {
                touch($argv[3]);
                usleep(400_000);
            });
            PHP;
        $process = new Process([PHP_BINARY, '-r', $script, $autoload, $directory, $ready]);
        $process->setTimeout(2.0);

        try {
            $process->start();
            waitForMetricsCredentialBarrier($ready, $process);
            $now = 0.0;
            $clock = static function () use (&$now): float {
                return $now;
            };
            $deadline = new CommandDeadline($clock);
            $deadline->start(0.03);
            $contender = new NativeMetricsCredentialOperationLock(
                $directory,
                $deadline,
                $clock,
                static function (int $microseconds) use (&$now): void {
                    $now += $microseconds / 1_000_000;
                },
            );
            $entered = false;

            expect(fn () => $contender->run(41, function () use (&$entered): void {
                $entered = true;
            }))
                ->toThrow(function (ResourceOperationException $exception): void {
                    expect($exception->errorCode)
                        ->toBe('metrics.credentials_busy')
                        ->and($exception->status)
                        ->toBe(409)
                        ->and($exception->getMessage())
                        ->toBe('Another Metrics credential operation is active for this Node. Retry the request.');
                });

            expect($entered)
                ->toBeFalse()
                ->and(round($now, 3))
                ->toBe(0.03)
                ->and($process->isRunning())
                ->toBeTrue()
                ->and($contender->run(42, static fn (): string => 'independent'))
                ->toBe('independent');

            $exitCode = $process->wait();

            expect($exitCode)
                ->toBe(0, $process->getErrorOutput());

            expect($contender->run(41, static fn (): string => 'released'))
                ->toBe('released')
                ->and(fileperms($directory) & 0o777)
                ->toBe(0o700)
                ->and(fileperms($directory.'/node-41.lock') & 0o777)
                ->toBe(0o600);
        } finally {
            if ($process->isRunning()) {
                $process->stop(0.1);
            }

            @unlink($ready);
            new Filesystem()->deleteDirectory($directory);
        }
    });

    it('keeps ownership through reentry and releases it after an exception', function (): void {
        $directory = sys_get_temp_dir().'/orbit-metrics-credential-exception-'.Str::uuid();
        $owner = new NativeMetricsCredentialOperationLock($directory, new CommandDeadline);

        try {
            expect(fn () => $owner->run(51, fn (): mixed => $owner->run(51, static function (): never {
                throw new RuntimeException('credential operation failed');
            })))
                ->toThrow(RuntimeException::class, 'credential operation failed');

            $next = new NativeMetricsCredentialOperationLock($directory, new CommandDeadline);

            expect($next->run(51, static fn (): string => 'released'))
                ->toBe('released');
        } finally {
            new Filesystem()->deleteDirectory($directory);
        }
    });

    it('releases ownership when the owning process exits inside the operation', function (): void {
        $directory = sys_get_temp_dir().'/orbit-metrics-credential-exit-'.Str::uuid();
        $autoload = base_path('vendor/autoload.php');
        $script = <<<'PHP'
            require $argv[1];
            $owner = new App\Infrastructure\Metrics\NativeMetricsCredentialOperationLock(
                $argv[2],
                new App\Infrastructure\Processes\CommandDeadline,
            );
            $owner->run(61, static function (): never {
                exit(0);
            });
            PHP;
        $process = new Process([PHP_BINARY, '-r', $script, $autoload, $directory]);

        try {
            $process->mustRun();
            $next = new NativeMetricsCredentialOperationLock($directory, new CommandDeadline);

            expect($next->run(61, static fn (): string => 'released'))
                ->toBe('released');
        } finally {
            new Filesystem()->deleteDirectory($directory);
        }
    });

    it('binds one native owner for each application request scope', function (): void {
        $first = app(MetricsCredentialOperationLock::class);
        $sameScope = app(MetricsCredentialOperationLock::class);

        app()->forgetScopedInstances();

        $nextScope = app(MetricsCredentialOperationLock::class);

        expect($first)
            ->toBeInstanceOf(NativeMetricsCredentialOperationLock::class)
            ->toBe($sameScope)
            ->not->toBe($nextScope);
    });
});

function waitForMetricsCredentialBarrier(string $path, Process $process): void
{
    $expiresAt = microtime(true) + 1.0;

    while (! is_file($path)) {
        if (! $process->isRunning()) {
            throw new RuntimeException("Metrics credential owner exited before its barrier: {$process->getErrorOutput()}");
        }

        if (microtime(true) >= $expiresAt) {
            throw new RuntimeException('Timed out waiting for the Metrics credential owner barrier.');
        }

        usleep(10_000);
    }
}
