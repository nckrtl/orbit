<?php

declare(strict_types=1);

use App\E2E\State\AtomicJsonStore;
use App\E2E\State\StatePaths;
use App\E2E\TopologySnapshotReplacementStore;
use App\E2E\Value\AttemptId;
use App\E2E\Value\LaravelRelease;
use App\E2E\Value\OperationId;
use App\E2E\Value\TopologyProfile;
use App\E2E\Value\TopologySnapshotGeneration;
use App\E2E\Value\TopologySnapshotReplacementInstallation;

function replacementStoreInstallationFixture(string $artifactSha = ''): TopologySnapshotReplacementInstallation
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

it('starts one exact active replacement idempotently', function (): void {
    $state = new AtomicJsonStore(new StatePaths(temporaryPath('replacement-store-', 8)));
    $store = new TopologySnapshotReplacementStore($state);
    $installation = replacementStoreInstallationFixture();

    $started = $store->start($installation, '2026-09-10T10:00:00Z');
    $repeated = $store->start($installation, '2026-09-10T10:00:01Z');

    expect($started->phase)
        ->toBe('authorized')
        ->and($repeated->toArray())->toBe($started->toArray())
        ->and($store->active()?->toArray())->toBe($started->toArray())
        ->and($state->read('topology-snapshot/replacement.json'))->toBe($started->toArray());
});

it('rejects identity changes and non-monotonic progress', function (): void {
    $state = new AtomicJsonStore(new StatePaths(temporaryPath('replacement-store-', 8)));
    $store = new TopologySnapshotReplacementStore($state);
    $started = $store->start(replacementStoreInstallationFixture(), '2026-09-10T10:00:00Z');
    $advanced = $started->withPhase('construction_pending', '2026-09-10T10:00:01Z');
    $store->advance($advanced);

    expect(fn () => $store->start(
        replacementStoreInstallationFixture(str_repeat('0', 40)),
        '2026-09-10T10:00:02Z',
    ))->toThrow(RuntimeException::class, 'different identity');

    expect(fn () => $store->advance($started))
        ->toThrow(RuntimeException::class, 'must be monotonic')
        ->and($store->active()?->toArray())->toBe($advanced->toArray());
});

it('leaves the previous active recovery intact when an atomic advance fails', function (): void {
    $paths = new StatePaths(temporaryPath('replacement-store-', 8));
    $stable = new AtomicJsonStore($paths);
    $store = new TopologySnapshotReplacementStore($stable);
    $started = $store->start(replacementStoreInstallationFixture(), '2026-09-10T10:00:00Z');
    $failure = new RuntimeException('simulated interruption');
    $failing = new TopologySnapshotReplacementStore(new AtomicJsonStore(
        $paths,
        static function (string $stage) use ($failure): void {
            if ($stage === 'before_rename') {
                throw $failure;
            }
        },
    ));
    $advanced = $started->withPhase('construction_pending', '2026-09-10T10:00:01Z');

    expect(fn () => $failing->advance($advanced))->toThrow($failure)
        ->and($store->active()?->toArray())->toBe($started->toArray());
});

it('archives terminal progress by proof attempt and clears active state', function (): void {
    $state = new AtomicJsonStore(new StatePaths(temporaryPath('replacement-store-', 8)));
    $store = new TopologySnapshotReplacementStore($state);
    $started = $store->start(replacementStoreInstallationFixture(), '2026-09-10T10:00:00Z');
    $abandoned = $started
        ->withTemporaryResourcesCleaned()
        ->withStagedResourcesCleaned()
        ->withPhase('abandoned', '2026-09-10T10:00:01Z');

    $store->complete($abandoned);

    expect($store->active())->toBeNull()
        ->and($store->archived($abandoned->installation->proofAttempt)?->toArray())
        ->toBe($abandoned->toArray())
        ->and($state->read(
            'topology-snapshot/replacements/'.$abandoned->installation->proofAttempt->value.'.json',
        ))->toBe($abandoned->toArray());

    expect(fn () => $store->start($abandoned->installation, '2026-09-10T10:00:02Z'))
        ->toThrow(RuntimeException::class, 'already archived');
});
