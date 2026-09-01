<?php

declare(strict_types=1);

use App\E2E\IncusHost;
use App\E2E\LegacyStandbyRecovery;
use App\E2E\StandbyManifestStore;
use App\E2E\State\AtomicJsonStore;
use App\E2E\State\StatePaths;
use App\E2E\Value\OperationId;
use App\E2E\Value\StandbyIdentity;
use App\E2E\Value\TopologyProfile;
use PHPUnit\Framework\Assert;
use Tests\Live\Support\LiveHarness;
use Tests\TestCase;

uses(TestCase::class);

it('writes a network-only recovery record for a fresh process', function (): void {
    if (getenv('ORBIT_LIVE_INCUS') !== '1') {
        test()->markTestSkipped('Set ORBIT_LIVE_INCUS=1 to run.');
    }

    $inputs = LiveHarness::inputs([
        'ORBIT_LIVE_MAIN_WORKTREE',
        'ORBIT_LIVE_CANDIDATE_SHA',
    ]);
    $paths = StatePaths::forPrimary($inputs['ORBIT_LIVE_MAIN_WORKTREE']);
    $store = new AtomicJsonStore($paths);
    $host = app(IncusHost::class);
    $recovery = new LegacyStandbyRecovery(
        $host,
        new StandbyManifestStore($store, $paths, $host),
        $store,
        app(OperationId::class),
        app(StandbyIdentity::class),
    );
    $inventory = $recovery->authorize();

    Assert::assertSame([], $inventory->instances);
    Assert::assertSame([], $inventory->snapshots);
    Assert::assertSame([StandbyIdentity::live()->network()], $inventory->resourceNames());

    $recovery->start($inputs['ORBIT_LIVE_CANDIDATE_SHA'], $inventory);
    $record = LiveHarness::jsonFile(
        rtrim($inputs['ORBIT_LIVE_MAIN_WORKTREE'], '/').'/.e2e/standby/recovery.json',
    );
    Assert::assertSame('authorized', $record['phase'] ?? null);
    Assert::assertSame([], $record['inventory']['instances'] ?? null);
    Assert::assertSame([], $record['inventory']['snapshots'] ?? null);
})->group('incus-live-network-record');

it('archives the completed network-only recovery before the next start', function (): void {
    if (getenv('ORBIT_LIVE_INCUS') !== '1') {
        test()->markTestSkipped('Set ORBIT_LIVE_INCUS=1 to run.');
    }

    $inputs = LiveHarness::inputs([
        'ORBIT_LIVE_MAIN_WORKTREE',
        'ORBIT_LIVE_CANDIDATE_SHA',
    ]);
    $root = rtrim($inputs['ORBIT_LIVE_MAIN_WORKTREE'], '/').'/.e2e';
    $completed = LiveHarness::jsonFile($root.'/standby/recovery.json');
    $operation = $completed['operation_id'] ?? null;
    Assert::assertSame('construction_verified', $completed['phase'] ?? null);
    Assert::assertSame([], $completed['inventory']['instances'] ?? null);
    Assert::assertSame([], $completed['inventory']['snapshots'] ?? null);
    Assert::assertMatchesRegularExpression('/\A[a-f0-9]{32}\z/D', is_string($operation) ? $operation : '');

    $result = LiveHarness::jsonWrapper(
        'standby',
        'recover-legacy',
        '--main-sha='.$inputs['ORBIT_LIVE_CANDIDATE_SHA'],
    );

    Assert::assertSame('promoted', $result['state'] ?? null);
    Assert::assertSame($completed, LiveHarness::jsonFile($root.'/standby/recoveries/'.$operation.'.json'));
    $current = LiveHarness::jsonFile($root.'/standby/recovery.json');
    Assert::assertSame('construction_verified', $current['phase'] ?? null);
    Assert::assertNotSame([], $current['inventory']['instances'] ?? []);
})->group('incus-live');

/**
 * The wrapper has just refused ordinary rebuild and completed the supported
 * legacy recovery. This read-only case proves its retained evidence and exact
 * final Incus state before the generic topology lifecycle uses the standby.
 *
 * @mago-expect analysis:mixed-assignment,mixed-array-access Live evidence is asserted one field at a time.
 */
it('retains exact legacy recovery evidence and a stopped replacement', function (): void {
    if (getenv('ORBIT_LIVE_INCUS') !== '1') {
        test()->markTestSkipped('Set ORBIT_LIVE_INCUS=1 to run.');
    }

    $inputs = LiveHarness::inputs([
        'ORBIT_LIVE_MAIN_WORKTREE',
        'ORBIT_LIVE_CANDIDATE_SHA',
    ]);
    $candidateSha = $inputs['ORBIT_LIVE_CANDIDATE_SHA'];
    $evidence = LiveHarness::jsonFile(
        rtrim($inputs['ORBIT_LIVE_MAIN_WORKTREE'], '/').'/.e2e/standby/recovery.json',
    );
    Assert::assertSame('construction_verified', $evidence['phase'] ?? null);
    Assert::assertSame($candidateSha, $evidence['main_sha'] ?? null);
    Assert::assertIsArray($evidence['inventory'] ?? null);
    Assert::assertSame(
        hash('sha256', json_encode($evidence['inventory'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)),
        $evidence['inventory_sha256'] ?? null,
    );

    $history = $evidence['history'] ?? null;
    Assert::assertIsArray($history);
    $expectedPhases = [
        'authorized',
        'instances_pending',
        'instances_verified',
        'network_pending',
        'network_verified',
        'manifests_pending',
        'manifests_verified',
        'construction_pending',
        'construction_verified',
    ];
    $observedPhases = array_column($history, 'phase');

    Assert::assertSame(
        $expectedPhases,
        array_values(array_filter(
            $observedPhases,
            static fn (string $phase): bool => in_array($phase, $expectedPhases, true),
        )),
        'Recovery evidence is missing an ordered phase or has an unexpected duplicate.',
    );

    $identity = StandbyIdentity::live();
    $status = LiveHarness::jsonWrapper('standby', 'status');
    Assert::assertSame('promoted', $status['state'] ?? null);
    Assert::assertTrue($status['stopped'] ?? false);
    Assert::assertSame($candidateSha, $status['generation']['main_sha'] ?? null);
    Assert::assertSame($identity->namespace, $status['standby_namespace'] ?? null);

    foreach (TopologyProfile::ROLES as $role) {
        $name = $identity->instance($role);
        $instance = LiveHarness::incusResource('instance', $name);
        Assert::assertSame('STOPPED', strtoupper((string) ($instance['status'] ?? '')));
        LiveHarness::assertIncusAbsent(["{$name}-next"]);
    }
    LiveHarness::incusResource('network', $identity->network());

    $authorizedNames = array_keys($evidence['inventory']['instances'] ?? []);
    Assert::assertNotContains('orbit-e2e-standby-gateway', $authorizedNames);
    Assert::assertNotSame('oe-standby', $evidence['inventory']['network']['name'] ?? null);
})->group('incus-live');
