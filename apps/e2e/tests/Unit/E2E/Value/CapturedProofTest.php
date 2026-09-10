<?php

declare(strict_types=1);

use App\E2E\Value\AttemptPurpose;
use App\E2E\Value\CapturedProof;
use App\E2E\Value\FeatureTopology;
use App\E2E\Value\LaravelRelease;
use App\E2E\Value\ProofInputManifest;
use App\E2E\Value\SourceState;
use App\E2E\Value\TopologySnapshotGeneration;
use App\E2E\Value\VerificationReport;

function capturedProofValue(): CapturedProof
{
    $candidate = str_repeat('a', 40);
    $construction = topologyConstructionFixture('ORB-230', 'b');
    $generation = new TopologySnapshotGeneration(
        'fixture-generation',
        str_repeat('c', 40),
        ['gateway' => 'main-gateway', 'app-dev' => 'main-app-dev', 'app-prod' => 'main-app-prod'],
        str_repeat('d', 64),
        str_repeat('e', 64),
        new LaravelRelease('v13.10.1', str_repeat('f', 40)),
        str_repeat('1', 64),
        2,
        'ubuntu-26.04-amd64-v1',
        'orbit-base-ubuntu-26.04-runtime',
        'gateway_app-dev_app-prod',
        ['gateway', 'app-dev', 'app-prod'],
        ['gateway', 'app-dev'],
    );
    $topology = new FeatureTopology(
        $construction,
        AttemptPurpose::Proof,
        $generation,
        new SourceState($candidate, $candidate),
        new VerificationReport(true, ['ready' => verificationProbeFixture()]),
    );
    $manifest = new ProofInputManifest(
        4,
        $candidate,
        str_repeat('2', 40),
        [],
        [],
        '.loop/proof/ORB-230.json',
        [],
        $construction,
        null,
        [
            'static_classification' => true,
            'proof_contract' => true,
            'checkout_literals' => true,
            'observed_processes' => true,
            'observed_paths' => true,
            'pcov_cleanup' => true,
        ],
    );
    $plan = str_repeat('3', 64);
    $proof = [
        'status' => 'proved',
        'issue' => 'ORB-230',
        'attempt_id' => str_repeat('b', 32),
        'candidate_sha' => $candidate,
        'plan_sha256' => $plan,
        'manifest_sha256' => $manifest->fingerprint(),
        'actions' => [],
    ];

    return new CapturedProof(
        'ORB-230',
        attemptId('b'),
        $candidate,
        $plan,
        $manifest->fingerprint(),
        $proof,
        $topology,
        $manifest->toArray(),
        '2026-09-10T10:00:00Z',
    );
}

it('round-trips immutable evidence with its exact identity and fingerprint', function (): void {
    $capture = capturedProofValue();

    expect(CapturedProof::fromArray($capture->toArray())->toArray())
        ->toBe($capture->toArray())
        ->and($capture->evidence())
        ->toBe([
            'proof' => $capture->proof,
            'topology' => $capture->topology->toArray(),
            'manifest' => $capture->manifest,
        ]);
});

it('rejects fingerprint tampering', function (): void {
    $value = capturedProofValue()->toArray();
    $value['candidate_sha'] = str_repeat('9', 40);

    expect(fn () => CapturedProof::fromArray($value))
        ->toThrow(InvalidArgumentException::class, 'result identity is invalid');
});

it('upgrades complete captures written before the typed schema', function (): void {
    $capture = capturedProofValue();
    $legacy = $capture->evidence();
    $legacy['proof']['recorded_at'] = '2026-09-10T10:00:00Z';

    expect(CapturedProof::fromStoredArray($legacy)->evidence())
        ->toBe($legacy);
});
