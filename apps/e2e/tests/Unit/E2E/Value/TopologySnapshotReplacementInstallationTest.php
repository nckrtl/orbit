<?php

declare(strict_types=1);

use App\E2E\Value\AttemptId;
use App\E2E\Value\LaravelRelease;
use App\E2E\Value\OperationId;
use App\E2E\Value\TopologyProfile;
use App\E2E\Value\TopologySnapshotGeneration;
use App\E2E\Value\TopologySnapshotReplacementInstallation;

function replacementInstallationValueFixture(): TopologySnapshotReplacementInstallation
{
    $old = new TopologySnapshotGeneration(
        'old-generation',
        str_repeat('1', 40),
        [
            'gateway' => 'main-old-gateway',
            'app-dev' => 'main-old-app-dev',
            'app-prod' => 'main-old-app-prod',
        ],
        str_repeat('2', 64),
        str_repeat('3', 64),
        new LaravelRelease('v13.10.1', str_repeat('4', 40)),
        str_repeat('5', 64),
        2,
        'ubuntu-26.04-amd64-v1',
        'orbit-base-ubuntu-26.04-runtime',
        TopologyProfile::NAME,
        TopologyProfile::ROLES,
        TopologyProfile::CHECKOUT_ROLES,
    );
    $new = new TopologySnapshotGeneration(
        'new-generation',
        str_repeat('6', 40),
        [
            'gateway' => 'main-new-gateway',
            'app-dev' => 'main-new-app-dev',
            'app-prod' => 'main-new-app-prod',
        ],
        str_repeat('7', 64),
        str_repeat('8', 64),
        new LaravelRelease('v13.10.1', str_repeat('4', 40)),
        str_repeat('9', 64),
        2,
        'ubuntu-26.04-amd64-v1',
        'orbit-base-ubuntu-26.04-runtime',
        TopologyProfile::NAME,
        TopologyProfile::ROLES,
        TopologyProfile::CHECKOUT_ROLES,
        'old-generation',
    );

    return new TopologySnapshotReplacementInstallation(
        'AUX-231',
        new AttemptId(str_repeat('a', 32)),
        new AttemptId(str_repeat('b', 32)),
        new OperationId(str_repeat('c', 32)),
        str_repeat('d', 40),
        str_repeat('e', 40),
        str_repeat('f', 40),
        str_repeat('6', 40),
        str_repeat('a', 64),
        str_repeat('b', 64),
        $old,
        $new,
        'orbit-base-ubuntu-26.04-runtime',
        str_repeat('8', 64),
        'oe-replacement',
        [
            'gateway' => 'replacement-gateway',
            'app-dev' => 'replacement-app-dev',
            'app-prod' => 'replacement-app-prod',
        ],
        [
            'gateway' => 'snapshot-gateway',
            'app-dev' => 'snapshot-app-dev',
            'app-prod' => 'snapshot-app-prod',
        ],
        [
            'gateway' => 'snapshot-gateway-next',
            'app-dev' => 'snapshot-app-dev-next',
            'app-prod' => 'snapshot-app-prod-next',
        ],
        [
            'gateway' => 'snapshot-gateway-old',
            'app-dev' => 'snapshot-app-dev-old',
            'app-prod' => 'snapshot-app-prod-old',
        ],
    );
}

it('round trips every immutable installation binding with a stable fingerprint', function (): void {
    $installation = replacementInstallationValueFixture();

    $restored = TopologySnapshotReplacementInstallation::fromArray($installation->toArray());

    expect($restored->toArray())
        ->toBe($installation->toArray())
        ->and($restored->fingerprint())
        ->toBe($installation->fingerprint())
        ->toMatch('/\A[a-f0-9]{64}\z/');
});

it('rejects unknown schema keys and incomplete ordered resource maps', function (): void {
    $value = replacementInstallationValueFixture()->toArray();
    $value['unknown'] = true;

    expect(fn () => TopologySnapshotReplacementInstallation::fromArray($value))
        ->toThrow(InvalidArgumentException::class, 'schema is invalid');

    $value = replacementInstallationValueFixture()->toArray();
    unset($value['old_instances']['app-prod']);

    expect(fn () => TopologySnapshotReplacementInstallation::fromArray($value))
        ->toThrow(InvalidArgumentException::class, 'each ordered role once');
});

it('rejects a new generation that differs from its exact main or generic base', function (): void {
    $value = replacementInstallationValueFixture()->toArray();
    $value['main_sha'] = str_repeat('0', 40);

    expect(fn () => TopologySnapshotReplacementInstallation::fromArray($value))
        ->toThrow(InvalidArgumentException::class, 'generation binding is invalid');

    $value = replacementInstallationValueFixture()->toArray();
    $value['generic_image_sha256'] = str_repeat('0', 64);

    expect(fn () => TopologySnapshotReplacementInstallation::fromArray($value))
        ->toThrow(InvalidArgumentException::class, 'generation binding is invalid');
});

it('rejects reused proof attempts and overlapping resource identities', function (): void {
    $value = replacementInstallationValueFixture()->toArray();
    $value['replacement_attempt_id'] = $value['proof_attempt_id'];

    expect(fn () => TopologySnapshotReplacementInstallation::fromArray($value))
        ->toThrow(InvalidArgumentException::class, 'must differ');

    $value = replacementInstallationValueFixture()->toArray();
    $value['next_instances']['gateway'] = $value['canonical_instances']['gateway'];

    expect(fn () => TopologySnapshotReplacementInstallation::fromArray($value))
        ->toThrow(InvalidArgumentException::class, 'must be distinct');
});
