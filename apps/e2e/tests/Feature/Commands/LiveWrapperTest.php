<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Process;

$wrapper = dirname(__DIR__, 5).'/bin/e2e-live';

it('prints usage and exits 64 without a candidate SHA', function () use ($wrapper): void {
    $outside = temporaryPath('orbit-e2e-live-', 8);
    mkdir($outside, 0700);

    $result = Process::path($outside)->run([$wrapper]);

    expect($result->exitCode())
        ->toBe(64)
        ->and($result->errorOutput())
        ->toContain('usage: bin/e2e-live <candidate-sha> [--rolling]');
});

it('rejects a candidate that is not a full commit SHA', function () use ($wrapper): void {
    $result = Process::run([$wrapper, 'main']);

    expect($result->exitCode())
        ->toBe(64)
        ->and($result->errorOutput())
        ->toContain('candidate SHA must be 40 hexadecimal characters');
});

it('rejects an unknown option before touching any repository', function () use ($wrapper): void {
    $result = Process::run([$wrapper, str_repeat('a', 40), '--rolling', '--force']);

    expect($result->exitCode())->toBe(64)->and($result->errorOutput())->toContain('unknown option: --force');
});

it('refuses a second run while the live lock is held', function () use ($wrapper): void {
    $state = temporaryPath('orbit-e2e-live-state-', 8);
    mkdir($state.'/orbit/e2e', 0700, true);
    $lock = $state.'/orbit/e2e/live.lock';

    $result = Process::env(['XDG_STATE_HOME' => $state])->run([
        'flock',
        $lock,
        $wrapper,
        str_repeat('a', 40),
    ]);

    expect($result->exitCode())
        ->toBe(75)
        ->and($result->errorOutput())
        ->toContain('another bin/e2e-live run holds '.$lock);
});

it('describes the validation clone, suites, and inputs with --help', function () use ($wrapper): void {
    $result = Process::run([$wrapper, '--help']);

    expect($result->exitCode())
        ->toBe(0)
        ->and($result->output())
        ->toContain(
            'ORBIT_E2E_VALIDATE_ROOT',
            'TopologyLedLifecycleAcceptanceTest',
            'RollingTopologyAcceptanceTest',
            '--rolling',
            'ACC-1',
            'ACC-2',
        );
});
