<?php

declare(strict_types=1);

use App\Data\NodeSetupExecutionResult;
use App\Exceptions\LocalNodeSetupException;
use App\Services\NodeSetup\ControllingTerminal;
use App\Services\NodeSetup\MacOsAppDevSetupRunner;
use App\Support\LocalDiagnosticRedactor;

beforeEach(function (): void {
    $this->setupRunnerDirectory = sys_get_temp_dir().'/orbit-runner-test-'.bin2hex(random_bytes(8));
    mkdir($this->setupRunnerDirectory, permissions: 0o700);
});

afterEach(function (): void {
    foreach (glob($this->setupRunnerDirectory.'/*') ?: [] as $path) {
        unlink($path);
    }

    rmdir($this->setupRunnerDirectory);
});

it('uses fixed typed argv and exclusive mode-0600 artifacts before cleanup', function (): void {
    expect(class_exists(MacOsAppDevSetupRunner::class))->toBeTrue();

    $process = new stdClass;
    $statuses = [
        ['pid' => 100, 'running' => true, 'exitcode' => -1],
        ['pid' => 100, 'running' => false, 'exitcode' => 0],
    ];
    $observed = [];
    $runner = new MacOsAppDevSetupRunner(
        new LocalDiagnosticRedactor,
        processStarter: function (array $argv, array $descriptors) use ($process, &$observed): object {
            $observed['argv'] = $argv;
            $observed['descriptor_resources'] = array_map(is_resource(...), $descriptors);
            $observed['script_mode'] = lstat($argv[6])['mode'] & 0o777;
            $observed['fifo_mode'] = lstat($argv[4])['mode'] & 0o777;
            $observed['fifo_type'] = lstat($argv[4])['mode'] & 0o170_000;
            $writer = fopen($argv[4], 'wb');
            fwrite($writer, "TOKEN=private-runner-value\nsetup complete");
            fclose($writer);

            return $process;
        },
        processStatus: static function () use (&$statuses): array {
            return array_shift($statuses) ?? ['pid' => 100, 'running' => false, 'exitcode' => 0];
        },
        processCloser: static fn (): int => 0,
        childLookup: static fn (): array => [],
        processInspector: static fn (): ?array => null,
        signalSender: static fn (): bool => true,
        sleeper: static fn (): null => null,
        tempDirectory: fn (): string => $this->setupRunnerDirectory,
        effectiveUid: static fn (): int => posix_geteuid(),
        currentPid: static fn (): int => 50,
    );

    $result = $runner->run(
        "#!/bin/bash\necho safe\n",
        setup_runner_terminal(),
    );

    expect($result)
        ->toBeInstanceOf(NodeSetupExecutionResult::class)
        ->and($result->exitCode)
        ->toBe(0)
        ->and($result->diagnostics)
        ->toContain('setup complete')
        ->not->toContain('private-runner-value')->and($observed['argv'])->toBe([
            '/usr/bin/script',
            '-q',
            '-e',
            '-F',
            $observed['argv'][4],
            '/bin/bash',
            $observed['argv'][6],
        ])->and($observed['descriptor_resources'])->toBe([true, true, true])->and($observed['script_mode'])->toBe(
            0o600,
        )->and($observed['fifo_mode'])->toBe(0o600)->and($observed['fifo_type'])->toBe(0o010_000)->and(
            $observed['argv'][4],
        )
        ->not->toBeFile()->and($observed['argv'][6])
        ->not->toBeFile();
});

it('signals only the verified owned target shape for a handled interrupt', function (
    array $children,
    array $expectedTargets,
): void {
    $signals = [];
    $terminated = false;
    $reaped = 0;
    $now = 0.0;
    $process = new stdClass;
    $uid = posix_geteuid();
    $runner = new MacOsAppDevSetupRunner(
        new LocalDiagnosticRedactor,
        processStarter: static fn (): object => $process,
        processStatus: static function () use (&$terminated): array {
            return [
                'pid' => 100,
                'running' => ! $terminated,
                'exitcode' => $terminated ? 2 : -1,
            ];
        },
        processCloser: static function () use (&$reaped): int {
            $reaped++;

            return 2;
        },
        childLookup: static function () use (&$terminated, $children): array {
            return $terminated ? [] : $children;
        },
        processGroupLookup: static function () use (&$terminated, $children): array {
            return $terminated ? [] : $children;
        },
        processInspector: static function (int $pid) use ($uid, &$terminated): ?array {
            if ($terminated && $pid !== 100) {
                return null;
            }

            return match ($pid) {
                100 => ['pid' => 100, 'ppid' => 50, 'uid' => $uid, 'pgid' => 100],
                101 => ['pid' => 101, 'ppid' => 100, 'uid' => $uid, 'pgid' => 101],
                102 => ['pid' => 102, 'ppid' => 100, 'uid' => $uid, 'pgid' => 102],
                default => null,
            };
        },
        signalSender: static function (int $target, int $signal) use (&$signals, &$terminated, $expectedTargets): bool {
            $signals[] = [$target, $signal];
            $terminated = count($signals) === count($expectedTargets);

            return true;
        },
        clock: static function () use (&$now): float {
            return $now;
        },
        sleeper: static function (int $microseconds) use (&$now): null {
            $now += $microseconds / 1_000_000;

            return null;
        },
        tempDirectory: fn (): string => $this->setupRunnerDirectory,
        effectiveUid: static fn (): int => posix_geteuid(),
        currentPid: static fn (): int => 50,
    );
    $runner->requestInterrupt();

    $result = $runner->run('#!/bin/bash', setup_runner_terminal());

    expect($result->exitCode)
        ->toBe(130)
        ->and($result->interrupted)
        ->toBeTrue()
        ->and($signals)
        ->toBe($expectedTargets)
        ->and($reaped)
        ->toBe(1)
        ->and(glob($this->setupRunnerDirectory.'/*') ?: [])
        ->toBeEmpty();
})->with([
    'zero children signals the exact parent after the bounded lookup' => [
        [],
        [[100, SIGINT]],
    ],
    'one process-group leader signals the exact negative group' => [
        ['101'],
        [[-101, SIGINT]],
    ],
    'multiple children signal exact positive children and parent' => [
        ['101', '102'],
        [[101, SIGINT], [102, SIGINT], [100, SIGINT]],
    ],
]);

it('continues group escalation until a verified descendant exits after its leader and parent', function (): void {
    $signals = [];
    $parentRunning = true;
    $leaderRunning = true;
    $descendantRunning = true;
    $reaped = 0;
    $now = 0.0;
    $process = new stdClass;
    $uid = posix_geteuid();
    $runner = new MacOsAppDevSetupRunner(
        new LocalDiagnosticRedactor,
        processStarter: static fn (): object => $process,
        processStatus: static function () use (&$parentRunning): array {
            return [
                'pid' => 100,
                'running' => $parentRunning,
                'exitcode' => $parentRunning ? -1 : 2,
            ];
        },
        processCloser: static function () use (&$reaped): int {
            $reaped++;

            return 2;
        },
        childLookup: static function (int $pid) use (&$leaderRunning, &$descendantRunning): array {
            return match ($pid) {
                100 => $leaderRunning ? ['101'] : [],
                101 => $descendantRunning ? ['102'] : [],
                default => [],
            };
        },
        processGroupLookup: static function () use (&$leaderRunning, &$descendantRunning): array {
            return [
                ...($leaderRunning ? ['101'] : []),
                ...($descendantRunning ? ['102'] : []),
            ];
        },
        processInspector: static function (int $pid) use (
            $uid,
            &$parentRunning,
            &$leaderRunning,
            &$descendantRunning,
        ): ?array {
            return match (true) {
                $pid === 100 && $parentRunning => ['pid' => 100, 'ppid' => 50, 'uid' => $uid, 'pgid' => 100],
                $pid === 101 && $leaderRunning => ['pid' => 101, 'ppid' => 100, 'uid' => $uid, 'pgid' => 101],
                $pid === 102 && $descendantRunning => [
                    'pid' => 102,
                    'ppid' => $leaderRunning ? 101 : 1,
                    'uid' => $uid,
                    'pgid' => 101,
                ],
                default => null,
            };
        },
        signalSender: static function (int $target, int $signal) use (
            &$signals,
            &$parentRunning,
            &$leaderRunning,
            &$descendantRunning,
        ): bool {
            $signals[] = [$target, $signal];

            if ($signal === SIGINT) {
                $parentRunning = false;
                $leaderRunning = false;
            }

            if ($signal === SIGTERM) {
                $descendantRunning = false;
            }

            return true;
        },
        clock: static function () use (&$now): float {
            return $now;
        },
        sleeper: static function (int $microseconds) use (&$now): null {
            $now += $microseconds / 1_000_000;

            return null;
        },
        tempDirectory: fn (): string => $this->setupRunnerDirectory,
        effectiveUid: static fn (): int => posix_geteuid(),
        currentPid: static fn (): int => 50,
    );
    $runner->requestInterrupt();

    $result = $runner->run('#!/bin/bash', setup_runner_terminal());

    expect($result->exitCode)
        ->toBe(130)
        ->and($descendantRunning)
        ->toBeFalse()
        ->and($signals)
        ->toBe([[-101, SIGINT], [-101, SIGTERM]])
        ->and($reaped)
        ->toBe(1)
        ->and(glob($this->setupRunnerDirectory.'/*') ?: [])
        ->toBeEmpty();
});

it('reaps an already exited setup parent without signaling', function (): void {
    $signals = [];
    $reaped = 0;
    $process = new stdClass;
    $runner = new MacOsAppDevSetupRunner(
        new LocalDiagnosticRedactor,
        processStarter: static fn (): object => $process,
        processStatus: static fn (): array => ['pid' => 100, 'running' => false, 'exitcode' => 2],
        processCloser: static function () use (&$reaped): int {
            $reaped++;

            return 2;
        },
        childLookup: static fn (): array => [],
        processInspector: static fn (): ?array => null,
        signalSender: static function (int $target, int $signal) use (&$signals): bool {
            $signals[] = [$target, $signal];

            return true;
        },
        tempDirectory: fn (): string => $this->setupRunnerDirectory,
        effectiveUid: static fn (): int => posix_geteuid(),
        currentPid: static fn (): int => 50,
    );
    $runner->requestInterrupt();

    $result = $runner->run('#!/bin/bash', setup_runner_terminal());

    expect($result->exitCode)
        ->toBe(130)
        ->and($signals)
        ->toBeEmpty()
        ->and($reaped)
        ->toBe(1)
        ->and(glob($this->setupRunnerDirectory.'/*') ?: [])
        ->toBeEmpty();
});

it('uses finite SIGINT SIGTERM and SIGKILL phases', function (): void {
    $signals = [];
    $terminated = false;
    $now = 0.0;
    $process = new stdClass;
    $uid = posix_geteuid();
    $runner = new MacOsAppDevSetupRunner(
        new LocalDiagnosticRedactor,
        processStarter: static fn (): object => $process,
        processStatus: static function () use (&$terminated): array {
            return [
                'pid' => 100,
                'running' => ! $terminated,
                'exitcode' => $terminated ? 2 : -1,
            ];
        },
        processCloser: static fn (): int => 2,
        childLookup: static fn (): array => [],
        processInspector: static fn (int $pid): array => [
            'pid' => $pid,
            'ppid' => 50,
            'uid' => $uid,
            'pgid' => 100,
        ],
        signalSender: static function (int $target, int $signal) use (&$signals, &$terminated): bool {
            $signals[] = [$target, $signal];
            $terminated = $signal === SIGKILL;

            return true;
        },
        clock: static function () use (&$now): float {
            return $now;
        },
        sleeper: static function (int $microseconds) use (&$now): null {
            $now += $microseconds / 1_000_000;

            return null;
        },
        tempDirectory: fn (): string => $this->setupRunnerDirectory,
        effectiveUid: static fn (): int => posix_geteuid(),
        currentPid: static fn (): int => 50,
    );
    $runner->requestInterrupt();

    $result = $runner->run('#!/bin/bash', setup_runner_terminal());

    expect($result->exitCode)
        ->toBe(130)
        ->and($signals)
        ->toBe([
            [100, SIGINT],
            [100, SIGTERM],
            [100, SIGKILL],
        ])
        ->and($now)
        ->toBeGreaterThanOrEqual(6.0)
        ->and(glob($this->setupRunnerDirectory.'/*') ?: [])
        ->toBeEmpty();
});

it('handles a later interrupt after the verified child starts', function (): void {
    $signals = [];
    $terminated = false;
    $process = new stdClass;
    $uid = posix_geteuid();
    $runner = null;
    $runner = new MacOsAppDevSetupRunner(
        new LocalDiagnosticRedactor,
        processStarter: static fn (): object => $process,
        processStatus: static function () use (&$terminated): array {
            return [
                'pid' => 100,
                'running' => ! $terminated,
                'exitcode' => $terminated ? 2 : -1,
            ];
        },
        processCloser: static fn (): int => 2,
        childLookup: static function () use (&$terminated): array {
            return $terminated ? [] : ['101'];
        },
        processGroupLookup: static function () use (&$terminated): array {
            return $terminated ? [] : ['101'];
        },
        processInspector: static function (int $pid) use ($uid, &$terminated): ?array {
            if ($terminated && $pid !== 100) {
                return null;
            }

            return (
                $pid === 100
                    ? ['pid' => 100, 'ppid' => 50, 'uid' => $uid, 'pgid' => 100]
                    : ['pid' => 101, 'ppid' => 100, 'uid' => $uid, 'pgid' => 101]
            );
        },
        signalSender: static function (int $target, int $signal) use (&$signals, &$terminated): bool {
            $signals[] = [$target, $signal];
            $terminated = true;

            return true;
        },
        sleeper: static function () use (&$runner): null {
            $runner?->requestInterrupt();

            return null;
        },
        tempDirectory: fn (): string => $this->setupRunnerDirectory,
        effectiveUid: static fn (): int => posix_geteuid(),
        currentPid: static fn (): int => 50,
    );

    $result = $runner->run('#!/bin/bash', setup_runner_terminal());

    expect($result->exitCode)
        ->toBe(130)
        ->and($signals)
        ->toBe([[-101, SIGINT]])
        ->and(glob($this->setupRunnerDirectory.'/*') ?: [])
        ->toBeEmpty();
});

it('fails closed after the finite SIGKILL deadline when the owned parent remains', function (): void {
    $signals = [];
    $now = 0.0;
    $process = new stdClass;
    $uid = posix_geteuid();
    $runner = new MacOsAppDevSetupRunner(
        new LocalDiagnosticRedactor,
        processStarter: static fn (): object => $process,
        processStatus: static fn (): array => ['pid' => 100, 'running' => true, 'exitcode' => -1],
        processCloser: static fn (): int => -1,
        childLookup: static fn (): array => [],
        processInspector: static fn (int $pid): array => [
            'pid' => $pid,
            'ppid' => 50,
            'uid' => $uid,
            'pgid' => 100,
        ],
        signalSender: static function (int $target, int $signal) use (&$signals): bool {
            $signals[] = [$target, $signal];

            return true;
        },
        clock: static function () use (&$now): float {
            return $now;
        },
        sleeper: static function (int $microseconds) use (&$now): null {
            $now += $microseconds / 1_000_000;

            return null;
        },
        tempDirectory: fn (): string => $this->setupRunnerDirectory,
        effectiveUid: static fn (): int => posix_geteuid(),
        currentPid: static fn (): int => 50,
    );
    $runner->requestInterrupt();

    expect(fn () => $runner->run('#!/bin/bash', setup_runner_terminal()))
        ->toThrow(LocalNodeSetupException::class, 'Local setup could not be completed.');
    expect($signals)
        ->toBe([
            [100, SIGINT],
            [100, SIGTERM],
            [100, SIGKILL],
        ])
        ->and($now)
        ->toBeGreaterThanOrEqual(8.0)
        ->and(glob($this->setupRunnerDirectory.'/*') ?: [])
        ->toHaveCount(2);
});

it('rejects non-numeric and foreign child state without sending a signal', function (
    array $children,
    Closure $inspector,
): void {
    $signals = [];
    $process = new stdClass;
    $runner = new MacOsAppDevSetupRunner(
        new LocalDiagnosticRedactor,
        processStarter: static fn (): object => $process,
        processStatus: static fn (): array => ['pid' => 100, 'running' => true, 'exitcode' => -1],
        processCloser: static fn (): int => -1,
        childLookup: static fn (): array => $children,
        processInspector: $inspector,
        signalSender: static function (int $target, int $signal) use (&$signals): bool {
            $signals[] = [$target, $signal];

            return true;
        },
        tempDirectory: fn (): string => $this->setupRunnerDirectory,
        effectiveUid: static fn (): int => posix_geteuid(),
        currentPid: static fn (): int => 50,
    );
    $runner->requestInterrupt();

    expect(fn () => $runner->run('#!/bin/bash', setup_runner_terminal()))
        ->toThrow(LocalNodeSetupException::class, 'Local setup could not be completed.');
    expect($signals)->toBeEmpty();
})->with([
    'non-numeric PID' => [
        ['not-a-pid'],
        static fn (): ?array => null,
    ],
    'foreign UID' => [
        ['101'],
        static fn (int $pid): array => [
            'pid' => $pid,
            'ppid' => $pid === 100 ? 50 : 100,
            'uid' => posix_geteuid() + 1,
            'pgid' => $pid,
        ],
    ],
    'foreign PPID' => [
        ['101'],
        static fn (int $pid): array => [
            'pid' => $pid,
            'ppid' => 999,
            'uid' => posix_geteuid(),
            'pgid' => $pid,
        ],
    ],
]);

it('cleans staged artifacts when the local process cannot start', function (): void {
    $runner = new MacOsAppDevSetupRunner(
        new LocalDiagnosticRedactor,
        processStarter: static fn (): false => false,
        tempDirectory: fn (): string => $this->setupRunnerDirectory,
        effectiveUid: static fn (): int => posix_geteuid(),
    );

    expect(fn () => $runner->run('#!/bin/bash', setup_runner_terminal()))
        ->toThrow(LocalNodeSetupException::class, 'Local setup could not be started.');
    expect(glob($this->setupRunnerDirectory.'/*') ?: [])->toBeEmpty();
});

it('preserves an unrelated script exit code two', function (): void {
    $process = new stdClass;
    $runner = new MacOsAppDevSetupRunner(
        new LocalDiagnosticRedactor,
        processStarter: static fn (): object => $process,
        processStatus: static fn (): array => ['pid' => 100, 'running' => false, 'exitcode' => 2],
        processCloser: static fn (): int => 2,
        childLookup: static fn (): array => [],
        tempDirectory: fn (): string => $this->setupRunnerDirectory,
        effectiveUid: static fn (): int => posix_geteuid(),
        currentPid: static fn (): int => 50,
    );

    expect($runner->run('#!/bin/bash', setup_runner_terminal())->exitCode)->toBe(2);
});

function setup_runner_terminal(): ControllingTerminal
{
    return new ControllingTerminal(
        availabilityProbe: static fn (): bool => true,
        opener: static fn () => fopen('php://temp', 'r+b'),
    );
}
