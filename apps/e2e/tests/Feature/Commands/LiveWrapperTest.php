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
        ->toContain('usage: bin/e2e-live <candidate-sha>');
});

it('rejects a candidate that is not a full commit SHA', function () use ($wrapper): void {
    $result = Process::run([$wrapper, 'main']);

    expect($result->exitCode())
        ->toBe(64)
        ->and($result->errorOutput())
        ->toContain('candidate SHA must be 40 hexadecimal characters');
});

it('rejects an unknown option before touching any repository', function () use ($wrapper): void {
    $result = Process::run([$wrapper, str_repeat('a', 40), '--force']);

    expect($result->exitCode())->toBe(64)->and($result->errorOutput())->toContain('unknown option: --force');
});

it('refuses a second run while the live lock is held', function () use ($wrapper): void {
    $clone = temporaryPath('orbit-e2e-live-clone-', 8);
    mkdir($clone.'/.e2e/locks', 0700, true);
    $lock = $clone.'/.e2e/locks/live.lock';

    $result = Process::env(['ORBIT_E2E_VALIDATE_ROOT' => $clone])->run([
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
            'LegacyStandbyRecoveryAcceptanceTest',
            'TopologyLedLifecycleAcceptanceTest',
            'recover-legacy',
            'proofs/ACC-1.json',
            'ACC-1',
        )
        ->not->toContain('--rolling');
});

it('compares only stable primary standby identity fields', function () use ($wrapper): void {
    $source = file_get_contents($wrapper);

    expect($source)
        ->toContain('with_entries(select(.key | startswith("volatile.") | not))')
        ->toContain('devices: (.expanded_devices // .devices // {})')
        ->toContain('managed,')
        ->not->toContain("jq -s '[.[][] | select(.name == \"oe-standby\"");
});
