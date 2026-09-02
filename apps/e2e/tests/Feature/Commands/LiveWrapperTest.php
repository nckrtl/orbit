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
            'LegacyTopologySnapshotRecoveryAcceptanceTest',
            'TopologyLedLifecycleAcceptanceTest',
            'recover-legacy',
            'proofs/ACC-1.json',
            'ACC-1',
        )
        ->not->toContain('--rolling');
});

it('compares only stable primary topology snapshot identity fields', function () use ($wrapper): void {
    $source = file_get_contents($wrapper);

    expect($source)
        ->toContain('with_entries(select(.key | startswith("volatile.") | not))')
        ->toContain('devices: (.expanded_devices // .devices // {})')
        ->toContain('managed,')
        ->not->toContain("jq -s '[.[][] | select(.name == \"oe-topo-snap\"");
});

it('routes a missing topology snapshot with present resources into resumable legacy recovery', function () use (
    $wrapper,
): void {
    $source = file_get_contents($wrapper);
    preg_match('/if \\[\\[ "\\$topology_snapshot_state" == missing.*?then(?<branch>.*?)elif/s', $source, $matches);
    $missingBranch = $matches['branch'] ?? '';

    expect($missingBranch)
        ->toContain('topology-snapshot rebuild')
        ->toContain('assert_rebuild_refusal')
        ->toContain('topology-snapshot recover-legacy');
});

it('migrates the retired topology snapshot before handling the current identity', function () use ($wrapper): void {
    $source = file_get_contents($wrapper);
    $migration = strpos($source, 'migrate_retired_topology_snapshot');
    $currentStatus = strpos($source, 'topology_snapshot_status=$(harness topology-snapshot status)');
    preg_match('/retired_topology_snapshot_present\(\) \{(?<body>.*?)\n\}/s', $source, $matches);
    $presenceCheck = $matches['body'] ?? '';

    expect($source)
        ->toContain(
            'orbit-e2e-live-standby-gateway',
            'orbit-e2e-live-standby-app-dev',
            'orbit-e2e-live-standby-app-prod',
            'oe-live-standby',
            'topology-snapshot recover-legacy',
            '--group=incus-live-retired-migration',
        )
        ->and($migration)
        ->toBeInt()
        ->toBeLessThan($currentStatus)
        ->and($presenceCheck)
        ->toContain(
            '.e2e/standby/recovery.json',
            'construction_verified',
        );
});

it('inspects a retired migration at the SHA retained by its recovery evidence', function () use ($wrapper): void {
    $source = file_get_contents($wrapper);
    $acceptance = file_get_contents(
        dirname(__DIR__, 2).'/Live/LegacyTopologySnapshotRecoveryAcceptanceTest.php',
    );

    expect($source)
        ->toContain(
            'retired_migration_sha=$(jq -er \'.main_sha | strings\' "$recovery_record")',
            'ORBIT_LIVE_RETIRED_MIGRATION_SHA="$retired_migration_sha"',
        )
        ->and($acceptance)
        ->toContain("'ORBIT_LIVE_RETIRED_MIGRATION_SHA'");
});

it('registers the network-only retained-record resume and archive proof', function () use ($wrapper): void {
    $source = file_get_contents($wrapper);

    expect($source)
        ->toContain(
            'prepare_network_only',
            '--group=incus-live-network-record',
            'live_instance_count',
            'live_network_count',
            'normalizing a retained network-only live topology snapshot',
            'resuming retained legacy recovery at its recorded SHA',
            'retained_sha=$(jq -er',
            'checkout_validation_sha "$retained_sha"',
            'checkout_validation_sha "$candidate"',
            '.inventory.instances | type == "array" and length == 0',
            '.inventory.snapshots | type == "array" and length == 0',
            'any(.history[]; .phase == "resumed")',
            'tests/Live/LegacyTopologySnapshotRecoveryAcceptanceTest.php',
        )
        ->and(substr_count($source, 'remove_guest_gateway_env'))
        ->toBe(4);
});
