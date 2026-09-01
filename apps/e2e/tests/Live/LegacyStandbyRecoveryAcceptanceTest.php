<?php

declare(strict_types=1);

use App\E2E\Value\StandbyIdentity;
use App\E2E\Value\TopologyProfile;
use PHPUnit\Framework\Assert;
use Tests\Live\Support\LiveHarness;
use Tests\TestCase;

uses(TestCase::class);

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
