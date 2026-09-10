<?php

declare(strict_types=1);

use App\E2E\Value\AttemptId;
use App\E2E\Value\LaravelRelease;
use App\E2E\Value\OperationId;
use App\E2E\Value\TopologyProfile;
use App\E2E\Value\TopologySnapshotGeneration;
use App\E2E\Value\TopologySnapshotReplacementInstallation;
use App\E2E\Value\TopologySnapshotReplacementRecovery;

function replacementRecoveryInstallationFixture(string $artifactSha = ''): TopologySnapshotReplacementInstallation
{
    $old = new TopologySnapshotGeneration(
        'old-generation', str_repeat('1', 40),
        ['gateway' => 'main-old-gateway', 'app-dev' => 'main-old-app-dev', 'app-prod' => 'main-old-app-prod'],
        str_repeat('2', 64), str_repeat('3', 64),
        new LaravelRelease('v13.10.1', str_repeat('4', 40)),
        str_repeat('5', 64), 2, 'ubuntu-26.04-amd64-v1', 'orbit-base-ubuntu-26.04-runtime',
        TopologyProfile::NAME, TopologyProfile::ROLES, TopologyProfile::CHECKOUT_ROLES,
    );
    $new = new TopologySnapshotGeneration(
        'new-generation', str_repeat('6', 40),
        ['gateway' => 'main-new-gateway', 'app-dev' => 'main-new-app-dev', 'app-prod' => 'main-new-app-prod'],
        str_repeat('7', 64), str_repeat('8', 64),
        new LaravelRelease('v13.10.1', str_repeat('4', 40)),
        str_repeat('9', 64), 2, 'ubuntu-26.04-amd64-v1', 'orbit-base-ubuntu-26.04-runtime',
        TopologyProfile::NAME, TopologyProfile::ROLES, TopologyProfile::CHECKOUT_ROLES, 'old-generation',
    );

    return new TopologySnapshotReplacementInstallation(
        'ORB-231', new AttemptId(str_repeat('a', 32)), new AttemptId(str_repeat('b', 32)),
        new OperationId(str_repeat('c', 32)), str_repeat('d', 40),
        $artifactSha === '' ? str_repeat('e', 40) : $artifactSha,
        str_repeat('f', 40), str_repeat('6', 40), str_repeat('a', 64), str_repeat('b', 64),
        $old, $new, 'orbit-base-ubuntu-26.04-runtime', str_repeat('8', 64), 'oe-replacement',
        ['gateway' => 'replacement-gateway', 'app-dev' => 'replacement-app-dev', 'app-prod' => 'replacement-app-prod'],
        ['gateway' => 'snapshot-gateway', 'app-dev' => 'snapshot-app-dev', 'app-prod' => 'snapshot-app-prod'],
        ['gateway' => 'snapshot-gateway-next', 'app-dev' => 'snapshot-app-dev-next', 'app-prod' => 'snapshot-app-prod-next'],
        ['gateway' => 'snapshot-gateway-old', 'app-dev' => 'snapshot-app-dev-old', 'app-prod' => 'snapshot-app-prod-old'],
    );
}

function completedReplacementRecoveryFixture(): TopologySnapshotReplacementRecovery
{
    $recovery = TopologySnapshotReplacementRecovery::authorized(
        replacementRecoveryInstallationFixture(),
        '2026-09-10T10:00:00Z',
    );
    foreach ([
        'construction_pending',
        'construction_verified',
        'staging_pending',
        'staging_verified',
        'swap_pending',
        'swap_in_progress',
    ] as $offset => $phase) {
        $recovery = $recovery->withPhase($phase, sprintf('2026-09-10T10:00:%02dZ', $offset + 1));
    }
    foreach (TopologyProfile::ROLES as $role) {
        $recovery = $recovery->withOldRename($role)->withNewRename($role);
    }
    $recovery = $recovery
        ->withPhase('manifest_pending', '2026-09-10T10:00:07Z')
        ->withGenerationRecorded()
        ->withPhase('manifest_promoted', '2026-09-10T10:00:08Z')
        ->withManifestPromoted()
        ->withPhase('old_cleanup_pending', '2026-09-10T10:00:09Z');
    foreach (TopologyProfile::ROLES as $role) {
        $recovery = $recovery->withOldDeleted($role);
    }

    return $recovery
        ->withPhase('old_cleanup_verified', '2026-09-10T10:00:10Z')
        ->withStagedResourcesCleaned()
        ->withPhase('temporary_cleanup_pending', '2026-09-10T10:00:11Z')
        ->withTemporaryResourcesCleaned()
        ->withPhase('temporary_cleanup_verified', '2026-09-10T10:00:12Z')
        ->withPhase('complete', '2026-09-10T10:00:13Z');
}

it('round trips complete monotonic replacement progress', function (): void {
    $recovery = completedReplacementRecoveryFixture();

    $restored = TopologySnapshotReplacementRecovery::fromArray($recovery->toArray());

    expect($restored->toArray())
        ->toBe($recovery->toArray())
        ->and($restored->terminal())->toBeTrue()
        ->and($restored->canReplace($recovery))->toBeTrue();
});

it('redacts errors and next actions before they enter the journal', function (): void {
    $recovery = TopologySnapshotReplacementRecovery::authorized(
        replacementRecoveryInstallationFixture(),
        '2026-09-10T10:00:00Z',
    )->withPhase('construction_pending', '2026-09-10T10:00:01Z')
        ->withPhase(
            'preparation_failed',
            '2026-09-10T10:00:02Z',
            'Authorization: Bearer super-secret',
            'retry https://operator:password@example.test',
        );

    expect($recovery->error)
        ->toBe('Authorization: [REDACTED]')
        ->and($recovery->nextAction)
        ->toBe('retry https://[REDACTED]@example.test')
        ->and(TopologySnapshotReplacementRecovery::fromArray($recovery->toArray())->toArray())
        ->toBe($recovery->toArray());
});

it('rejects non-monotonic phase history and progress regression', function (): void {
    $authorized = TopologySnapshotReplacementRecovery::authorized(
        replacementRecoveryInstallationFixture(),
        '2026-09-10T10:00:00Z',
    );
    $advanced = $authorized->withPhase('construction_pending', '2026-09-10T10:00:01Z');
    $value = $advanced->toArray();
    $value['history'][] = ['phase' => 'authorized', 'recorded_at' => '2026-09-10T10:00:02Z'];
    $value['phase'] = 'authorized';

    expect(fn () => TopologySnapshotReplacementRecovery::fromArray($value))
        ->toThrow(InvalidArgumentException::class, 'phase transition is invalid')
        ->and($authorized->canReplace($advanced))
        ->toBeFalse();
});

it('rejects a forged installation fingerprint and incomplete completion', function (): void {
    $value = TopologySnapshotReplacementRecovery::authorized(
        replacementRecoveryInstallationFixture(),
        '2026-09-10T10:00:00Z',
    )->toArray();
    $value['installation_sha256'] = str_repeat('0', 64);

    expect(fn () => TopologySnapshotReplacementRecovery::fromArray($value))
        ->toThrow(InvalidArgumentException::class, 'fingerprint differs');

    expect(fn () => TopologySnapshotReplacementRecovery::authorized(
        replacementRecoveryInstallationFixture(),
        '2026-09-10T10:00:00Z',
    )->withPhase('complete', '2026-09-10T10:00:01Z'))
        ->toThrow(InvalidArgumentException::class);
});
