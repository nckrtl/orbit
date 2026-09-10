<?php

declare(strict_types=1);

use App\E2E\Git\GitRepository;
use App\E2E\IncusHost;
use App\E2E\IncusNetworkLifecycle;
use App\E2E\IssueState;
use App\E2E\OrphanNetworkSweep;
use App\E2E\State\AtomicJsonStore;
use App\E2E\State\StatePaths;
use App\E2E\TopologyReleaser;
use App\E2E\Value\AttemptId;
use App\E2E\Value\AttemptPurpose;
use App\E2E\Value\CapturedProof;
use App\E2E\Value\FeatureTopology;
use App\E2E\Value\LaravelRelease;
use App\E2E\Value\LeaseTargetRecovery;
use App\E2E\Value\OperationId;
use App\E2E\Value\ProofInputManifest;
use App\E2E\Value\ProofReleaseReason;
use App\E2E\Value\SourceState;
use App\E2E\Value\TopologyConstructionInputs;
use App\E2E\Value\TopologyExtension;
use App\E2E\Value\TopologyProfile;
use App\E2E\Value\TopologyRecipe;
use App\E2E\Value\TopologyRequest;
use App\E2E\Value\TopologySnapshotGeneration;
use App\E2E\Value\TopologyTarget;
use App\E2E\Value\VerificationReport;
use Illuminate\Container\Container;
use Illuminate\Process\Factory as ProcessFactory;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Process;

/**
 * A host holding the attempt's VMs and network with the given metadata; every
 * mutation is recorded and reflected in later reads.
 *
 *
 * @param  array<string, string>  $metadata
 * @param  list<string>  $commands
 */
function fakeReleaseHost(
    TopologyTarget $target,
    array $metadata,
    array &$commands,
    bool $network = true,
    ?array $presentNames = null,
    ?string $failCommandContaining = null,
): void {
    $present = $presentNames ?? array_map($target->instance(...), $target->recipe->nodeKeys());
    Process::fake(function (PendingProcess $process) use (
        $target,
        $metadata,
        &$commands,
        &$present,
        &$network,
        $failCommandContaining,
    ) {
        $command = $process->command;
        assert(is_array($command));
        $commands[] = implode(' ', array_slice($command, 3));
        if (
            $failCommandContaining !== null
            && str_contains(implode(' ', $command), $failCommandContaining)
        ) {
            return Process::result(errorOutput: 'injected cleanup failure', exitCode: 1);
        }
        if (($command[0] ?? null) === 'git') {
            $gitCommand = ['git', '-C', $process->path ?? getcwd(), ...array_slice($command, 1)];
            $output = [];
            $exitCode = 0;
            exec(implode(' ', array_map(escapeshellarg(...), $gitCommand)).' 2>&1', $output, $exitCode);

            return Process::result(implode("\n", $output), exitCode: $exitCode);
        }
        if (($command[0] ?? null) === 'python3') {
            return Process::result('{"changed":true}');
        }
        if (($command[3] ?? null) === 'network' && ($command[4] ?? null) === 'list') {
            return Process::result(json_encode(
                $network
                    ? [[
                        'name' => $target->network(),
                        'config' => ['user.orbit.e2e.owner' => 'orbit-e2e', ...$metadata],
                        'used_by' => [],
                    ]] : [],
                JSON_THROW_ON_ERROR,
            ));
        }
        if (($command[3] ?? null) === 'network' && ($command[4] ?? null) === 'delete') {
            $network = false;

            return Process::result();
        }
        if (($command[3] ?? null) === 'list') {
            $wanted = ($command[4] ?? '') === 'local:'
                ? $present
                : array_intersect($present, [substr((string) $command[4], 6)]);

            return Process::result(json_encode(
                array_values(array_map(
                    static fn (string $name): array => [
                        'name' => $name,
                        'type' => 'virtual-machine',
                        'status' => 'Running',
                        'status_code' => 103,
                        'config' => ['user.orbit.e2e.owner' => 'orbit-e2e', ...$metadata],
                        'devices' => ['root' => ['pool' => 'default'], 'eth0' => ['network' => $target->network()]],
                    ],
                    $wanted,
                )),
                JSON_THROW_ON_ERROR,
            ));
        }
        if (($command[3] ?? null) === 'delete') {
            $present = array_values(array_diff($present, [substr((string) $command[4], 6)]));
        }

        return Process::result();
    });
}

function extendedReleaseTopology(TopologyTarget $target, AttemptPurpose $purpose): FeatureTopology
{
    $generation = new TopologySnapshotGeneration(
        'g-'.str_repeat('a', 12),
        str_repeat('b', 40),
        ['gateway' => 'main-gateway', 'app-dev' => 'main-app-dev', 'app-prod' => 'main-app-prod'],
        str_repeat('c', 64),
        str_repeat('d', 64),
        new LaravelRelease('v13.10.1', str_repeat('e', 40)),
        str_repeat('f', 64),
        2,
        'ubuntu-26.04-amd64-v1',
        TopologyRecipe::BASE_IMAGE,
        TopologyProfile::NAME,
        TopologyProfile::ROLES,
        TopologyProfile::CHECKOUT_ROLES,
    );

    return new FeatureTopology(
        TopologyConstructionInputs::create(
            $target,
            $generation,
            2,
            TopologyExtension::AppProd,
            str_repeat('b', 64),
        ),
        $purpose,
        $generation,
        new SourceState(str_repeat('a', 40), str_repeat('a', 40)),
        new VerificationReport(true, ['ready' => verificationProbeFixture(probe: 'ready')]),
    );
}

function replacementReleaseTopology(TopologyTarget $target, AttemptPurpose $purpose): FeatureTopology
{
    $generation = new TopologySnapshotGeneration(
        'g-'.str_repeat('a', 12),
        str_repeat('b', 40),
        ['gateway' => 'main-gateway', 'app-dev' => 'main-app-dev', 'app-prod' => 'main-app-prod'],
        str_repeat('c', 64),
        str_repeat('d', 64),
        new LaravelRelease('v13.10.1', str_repeat('e', 40)),
        str_repeat('f', 64),
        2,
        'ubuntu-26.04-amd64-v1',
        TopologyRecipe::BASE_IMAGE,
        TopologyProfile::NAME,
        TopologyProfile::ROLES,
        TopologyProfile::CHECKOUT_ROLES,
    );

    return new FeatureTopology(
        TopologyConstructionInputs::forSnapshotReplacement(
            $target,
            2,
            TopologyRecipe::BASE_IMAGE,
            str_repeat('d', 64),
        ),
        $purpose,
        $generation,
        new SourceState(str_repeat('a', 40), str_repeat('a', 40)),
        new VerificationReport(true, ['ready' => verificationProbeFixture(probe: 'ready')]),
    );
}

function releaserForTest(StatePaths $paths, ?Closure $abandonReplacement = null): TopologyReleaser
{
    $host = new IncusHost;
    $operation = new OperationId(str_repeat('b', 32));

    return new TopologyReleaser(
        $host,
        new IncusNetworkLifecycle($host),
        $paths,
        $operation,
        new OrphanNetworkSweep($host, new IncusNetworkLifecycle($host), $paths, $operation),
        $abandonReplacement ?? static function (): void {},
    );
}

/** @return array{IssueState, TopologyTarget} */
function capturedReleaseState(
    string $worktree,
    StatePaths $hostPaths,
    bool $snapshotReplacement = false,
): array {
    $issue = 'AUX-99';
    $attempt = new AttemptId(str_repeat('a', 32));
    $target = TopologyTarget::feature(
        $issue,
        $attempt,
        $snapshotReplacement ? TopologyRecipe::registered() : TopologyRecipe::extendedAppProd(),
    );
    $topology = $snapshotReplacement
        ? replacementReleaseTopology($target, AttemptPurpose::Proof)
        : extendedReleaseTopology($target, AttemptPurpose::Proof);
    $manifest = new ProofInputManifest(
        4,
        str_repeat('a', 40),
        str_repeat('b', 40),
        [],
        [],
        '.loop/proof/AUX-99.json',
        [],
        $topology->construction,
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
    $proof = [
        'status' => 'proved',
        'issue' => $issue,
        'attempt_id' => $attempt->value,
        'candidate_sha' => str_repeat('a', 40),
        'plan_sha256' => str_repeat('c', 64),
        'manifest_sha256' => $manifest->fingerprint(),
        'actions' => [],
    ];
    $state = IssueState::forWorktree($issue, $worktree);
    $state->writeAttempt(
        $attempt,
        AttemptPurpose::Proof,
        new OperationId(str_repeat('d', 32)),
        $snapshotReplacement ? null : TopologyExtension::AppProd,
        $snapshotReplacement,
    );
    $state->writeTopology($topology);
    $state->writeProof($proof);
    $capture = new CapturedProof(
        $issue,
        $attempt,
        str_repeat('a', 40),
        str_repeat('c', 64),
        $manifest->fingerprint(),
        $proof,
        $topology,
        $manifest->toArray(),
        '2026-09-10T10:00:00Z',
    );
    $state->captureProof($capture);
    new AtomicJsonStore($hostPaths)->write(
        'proof-evidence/'.$issue.'/'.$attempt->value.'.json',
        $capture->toArray(),
    );

    return [$state, $target];
}

describe('TopologyReleaser', function () {
    beforeEach(function () {
        $container = new Container;
        $container->instance(ProcessFactory::class, new ProcessFactory);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($container);
    });

    it('retains successful captured proof unless replacement or abandonment is explicit', function (
        ProofReleaseReason $reason,
    ): void {
        $worktree = temporaryPath('orbit-release-captured-', 4);
        mkdir($worktree, 0700);
        $paths = new StatePaths(temporaryPath('orbit-release-host-', 4));
        [$state, $target] = capturedReleaseState($worktree, $paths);
        $commands = [];
        fakeReleaseHost(
            $target,
            ['user.orbit.e2e.issue' => 'AUX-99', 'user.orbit.e2e.attempt' => str_repeat('a', 32)],
            $commands,
        );

        expect(fn () => releaserForTest($paths)->release(
            new TopologyRequest('AUX-99', $worktree),
            AttemptPurpose::Proof,
        ))
            ->toThrow(RuntimeException::class, 'successful proof remains retained')
            ->and($commands)
            ->toBe([]);

        $result = releaserForTest($paths)->release(
            new TopologyRequest('AUX-99', $worktree),
            AttemptPurpose::Proof,
            reason: $reason,
        );

        expect($result['state'])
            ->toBe('released')
            ->and($state->hasAttempt(AttemptPurpose::Proof))
            ->toBeFalse()
            ->and($state->capturedProof()?->attempt->value)
            ->toBe(str_repeat('a', 32));
    })->with([
        'replacement' => ProofReleaseReason::Replacement,
        'abandonment' => ProofReleaseReason::Abandonment,
    ]);

    it('cleans replacement state before releasing an explicitly abandoned proof', function (): void {
        $worktree = temporaryPath('orbit-release-abandonment-', 4);
        mkdir($worktree, 0700);
        $paths = new StatePaths(temporaryPath('orbit-release-host-', 4));
        [$state, $target] = capturedReleaseState($worktree, $paths, snapshotReplacement: true);
        $commands = [];
        fakeReleaseHost(
            $target,
            ['user.orbit.e2e.issue' => 'AUX-99', 'user.orbit.e2e.attempt' => str_repeat('a', 32)],
            $commands,
        );
        $abandoned = [];
        $releaser = releaserForTest(
            $paths,
            function (TopologyRequest $request, CapturedProof $capture) use (&$abandoned): void {
                $abandoned = [$request->issue, $capture->attempt->value];
            },
        );

        $result = $releaser->release(
            new TopologyRequest('AUX-99', $worktree),
            AttemptPurpose::Proof,
            reason: ProofReleaseReason::Abandonment,
        );

        expect($abandoned)
            ->toBe(['AUX-99', str_repeat('a', 32)])
            ->and($result['state'])
            ->toBe('released')
            ->and($state->hasAttempt(AttemptPurpose::Proof))
            ->toBeFalse();
    });

    it('retains proof resources when replacement abandonment fails', function (): void {
        $worktree = temporaryPath('orbit-release-abandonment-failed-', 4);
        mkdir($worktree, 0700);
        $paths = new StatePaths(temporaryPath('orbit-release-host-', 4));
        [$state, $target] = capturedReleaseState($worktree, $paths, snapshotReplacement: true);
        $commands = [];
        fakeReleaseHost(
            $target,
            ['user.orbit.e2e.issue' => 'AUX-99', 'user.orbit.e2e.attempt' => str_repeat('a', 32)],
            $commands,
        );

        expect(fn () => releaserForTest(
            $paths,
            static function (): void {
                throw new RuntimeException('replacement cleanup failed');
            },
        )->release(
            new TopologyRequest('AUX-99', $worktree),
            AttemptPurpose::Proof,
            reason: ProofReleaseReason::Abandonment,
        ))
            ->toThrow(RuntimeException::class, 'replacement cleanup failed')
            ->and($commands)
            ->toBe([])
            ->and($state->hasAttempt(AttemptPurpose::Proof))
            ->toBeTrue();
    });

    it('releases an ordinary proof without consulting another issue replacement', function (): void {
        $worktree = temporaryPath('orbit-release-other-proof-', 4);
        mkdir($worktree, 0700);
        $paths = new StatePaths(temporaryPath('orbit-release-host-', 4));
        [$state, $target] = capturedReleaseState($worktree, $paths);
        $commands = [];
        fakeReleaseHost(
            $target,
            ['user.orbit.e2e.issue' => 'AUX-99', 'user.orbit.e2e.attempt' => str_repeat('a', 32)],
            $commands,
        );
        $replacementConsulted = false;
        $releaser = releaserForTest(
            $paths,
            static function () use (&$replacementConsulted): void {
                $replacementConsulted = true;

                throw new RuntimeException('AUX-231 has an active snapshot replacement.');
            },
        );

        $result = $releaser->release(
            new TopologyRequest('AUX-99', $worktree),
            AttemptPurpose::Proof,
            reason: ProofReleaseReason::Abandonment,
        );

        expect($replacementConsulted)
            ->toBeFalse()
            ->and($result['state'])->toBe('released')
            ->and($state->hasAttempt(AttemptPurpose::Proof))->toBeFalse();
    });

    it('protects a captured active proof when mutable proof result state is missing or mismatched', function (
        string $proofState,
    ): void {
        $worktree = temporaryPath('orbit-release-captured-state-', 4);
        mkdir($worktree, 0700);
        $paths = new StatePaths(temporaryPath('orbit-release-host-', 4));
        [$state, $target] = capturedReleaseState($worktree, $paths);
        if ($proofState === 'missing') {
            unlink($worktree.'/.e2e/'.IssueState::PROOF);
        } else {
            $state->writeProof(['status' => 'diagnosis', 'attempt_id' => str_repeat('f', 32)]);
        }
        $commands = [];
        fakeReleaseHost(
            $target,
            ['user.orbit.e2e.issue' => 'AUX-99', 'user.orbit.e2e.attempt' => str_repeat('a', 32)],
            $commands,
        );

        expect(fn () => releaserForTest($paths)->release(
            new TopologyRequest('AUX-99', $worktree),
            AttemptPurpose::Proof,
        ))
            ->toThrow(RuntimeException::class, 'successful proof remains retained')
            ->and($commands)
            ->toBe([])
            ->and($state->hasAttempt(AttemptPurpose::Proof))
            ->toBeTrue();
    })->with(['missing', 'mismatched']);

    it('verifies already absent captured resources and completes cleanup without a lease', function (): void {
        $worktree = temporaryPath('orbit-release-captured-absent-', 4);
        mkdir($worktree, 0700);
        $paths = new StatePaths(temporaryPath('orbit-release-host-', 4));
        [$state, $target] = capturedReleaseState($worktree, $paths);
        $capture = $state->capturedProof() ?? throw new RuntimeException('Fixture capture is missing.');
        $state->forgetAttempt(AttemptPurpose::Proof);
        $commands = [];
        fakeReleaseHost(
            $target,
            ['user.orbit.e2e.issue' => 'AUX-99', 'user.orbit.e2e.attempt' => str_repeat('a', 32)],
            $commands,
            network: false,
            presentNames: [],
        );

        $result = releaserForTest($paths)->releaseCapturedProof(
            new TopologyRequest('AUX-99', $worktree),
            $capture,
        );

        expect($result['state'])
            ->toBe('released')
            ->and($result['attempt_id'])
            ->toBe($capture->attempt->value)
            ->and($result['released'])
            ->toBe([])
            ->and($result['already_absent'])
            ->toEqualCanonicalizing([
                ...array_map($target->instance(...), $target->recipe->nodeKeys()),
                $target->network(),
            ]);
    });

    it('stops and deletes the exact VMs and network, drops the lease, and keeps the proof', function () {
        $worktree = temporaryPath('orbit-release-worktree-', 4);
        mkdir($worktree, 0700);
        $paths = new StatePaths(temporaryPath('orbit-release-host-', 4));
        $attempt = new AttemptId(str_repeat('a', 32));
        $target = TopologyTarget::feature('TST-12', $attempt);
        $state = IssueState::forWorktree('TST-12', $worktree);
        $state->writeAttempt($attempt, AttemptPurpose::Proof, new OperationId(str_repeat('c', 32)));
        $state->writeProof(['status' => 'diagnosis', 'attempt_id' => $attempt->value]);
        $commands = [];
        fakeReleaseHost(
            $target,
            ['user.orbit.e2e.issue' => 'TST-12', 'user.orbit.e2e.attempt' => $attempt->value],
            $commands,
        );

        $result = releaserForTest($paths)->release(
            new TopologyRequest('TST-12', $worktree),
            AttemptPurpose::Proof,
        );

        expect($result['state'])
            ->toBe('released')
            ->and($result['attempt_id'])
            ->toBe($attempt->value)
            ->and($result['already_absent'])
            ->toBe([])
            ->and($result['networks_reaped'])
            ->toBe([])
            ->and($result['released'])
            ->toEqualCanonicalizing([
                'stopped:'.$target->instance('gateway'),
                'stopped:'.$target->instance('app-dev'),
                'stopped:'.$target->instance('app-prod'),
                'deleted:'.$target->instance('gateway'),
                'deleted:'.$target->instance('app-dev'),
                'deleted:'.$target->instance('app-prod'),
                'deleted:'.$target->network(),
            ])
            ->and($state->hasAttempt())
            ->toBeFalse()
            ->and($state->proof()['status'] ?? null)
            ->toBe('diagnosis')
            ->and(array_values(array_filter($commands, static fn (string $command): bool => str_starts_with(
                $command,
                'delete',
            ))))
            ->toBe([
                'delete local:'.$target->instance('app-prod'),
                'delete local:'.$target->instance('app-dev'),
                'delete local:'.$target->instance('gateway'),
            ]);
    });

    it('releases a failed proof without releasing the discovery attempt', function () {
        $worktree = temporaryPath('orbit-release-worktree-', 4);
        mkdir($worktree, 0700);
        $paths = new StatePaths(temporaryPath('orbit-release-host-', 4));
        $discovery = new AttemptId(str_repeat('a', 32));
        $proof = new AttemptId(str_repeat('b', 32));
        $proofTarget = TopologyTarget::feature('AUX-7', $proof);
        $state = IssueState::forWorktree('AUX-7', $worktree);
        $state->writeAttempt($discovery, AttemptPurpose::Discovery, new OperationId(str_repeat('c', 32)));
        $state->writeAttempt($proof, AttemptPurpose::Proof, new OperationId(str_repeat('d', 32)));
        $state->writeProof(['status' => 'diagnosis', 'attempt_id' => $proof->value]);
        $commands = [];
        fakeReleaseHost(
            $proofTarget,
            ['user.orbit.e2e.issue' => 'AUX-7', 'user.orbit.e2e.attempt' => $proof->value],
            $commands,
        );

        $result = releaserForTest($paths)->release(
            new TopologyRequest('AUX-7', $worktree),
            AttemptPurpose::Proof,
        );

        expect($result['purpose'])
            ->toBe('proof')
            ->and($state->hasAttempt(AttemptPurpose::Discovery))
            ->toBeTrue()
            ->and($state->attemptId(AttemptPurpose::Discovery)->value)
            ->toBe($discovery->value)
            ->and($state->hasAttempt(AttemptPurpose::Proof))
            ->toBeFalse()
            ->and($state->proof()['status'] ?? null)
            ->toBe('diagnosis');
    });

    it('releases the exact persisted four-Node inventory without changing the shared generation', function (): void {
        $worktree = temporaryPath('orbit-release-extended-', 4);
        mkdir($worktree, 0700);
        $paths = new StatePaths(temporaryPath('orbit-release-host-', 4));
        $attempt = new AttemptId(str_repeat('a', 32));
        $target = TopologyTarget::feature('AUX-132', $attempt, TopologyRecipe::extendedAppProd());
        $state = IssueState::forWorktree('AUX-132', $worktree);
        $topology = extendedReleaseTopology($target, AttemptPurpose::Discovery);
        $state->writeAttempt(
            $attempt,
            AttemptPurpose::Discovery,
            new OperationId(str_repeat('c', 32)),
            TopologyExtension::AppProd,
        );
        $state->writeTopology($topology);
        $generation = $topology->generation->toArray();
        $commands = [];
        fakeReleaseHost(
            $target,
            ['user.orbit.e2e.issue' => 'AUX-132', 'user.orbit.e2e.attempt' => $attempt->value],
            $commands,
        );

        $result = releaserForTest($paths)->release(new TopologyRequest('AUX-132', $worktree));

        expect($result['released'])
            ->toBe([
                'stopped:'.$target->instance('app-prod-2'),
                'stopped:'.$target->instance('app-prod'),
                'stopped:'.$target->instance('app-dev'),
                'stopped:'.$target->instance('gateway'),
                'deleted:'.$target->instance('app-prod-2'),
                'deleted:'.$target->instance('app-prod'),
                'deleted:'.$target->instance('app-dev'),
                'deleted:'.$target->instance('gateway'),
                'deleted:'.$target->network(),
            ])
            ->and($state->hasAttempt(AttemptPurpose::Discovery))
            ->toBeFalse()
            ->and($generation['snapshots'])
            ->toBe(['gateway' => 'main-gateway', 'app-dev' => 'main-app-dev', 'app-prod' => 'main-app-prod'])
            ->and(array_values(array_filter($commands, static fn (string $command): bool => str_starts_with(
                $command,
                'delete',
            ))))
            ->toBe([
                'delete local:'.$target->instance('app-prod-2'),
                'delete local:'.$target->instance('app-prod'),
                'delete local:'.$target->instance('app-dev'),
                'delete local:'.$target->instance('gateway'),
            ]);
    });

    it('retains an extended lease on conflict and retries when only partial resources remain', function (): void {
        $worktree = temporaryPath('orbit-release-extended-retry-', 4);
        mkdir($worktree, 0700);
        $paths = new StatePaths(temporaryPath('orbit-release-host-', 4));
        $attempt = new AttemptId(str_repeat('a', 32));
        $target = TopologyTarget::feature('AUX-132', $attempt, TopologyRecipe::extendedAppProd());
        $state = IssueState::forWorktree('AUX-132', $worktree);
        $state->writeAttempt(
            $attempt,
            AttemptPurpose::Discovery,
            new OperationId(str_repeat('c', 32)),
            TopologyExtension::AppProd,
        );
        $state->writeTopology(extendedReleaseTopology($target, AttemptPurpose::Discovery));
        $commands = [];
        fakeReleaseHost(
            $target,
            ['user.orbit.e2e.issue' => 'AUX-132', 'user.orbit.e2e.attempt' => str_repeat('f', 32)],
            $commands,
        );

        expect(fn () => releaserForTest($paths)->release(new TopologyRequest('AUX-132', $worktree)))
            ->toThrow(RuntimeException::class, 'ownership does not match the issue attempt')
            ->and(array_filter($commands, static fn (string $command): bool => str_starts_with($command, 'delete')))
            ->toBe([])
            ->and($state->hasAttempt(AttemptPurpose::Discovery))
            ->toBeTrue();

        $commands = [];
        fakeReleaseHost(
            $target,
            ['user.orbit.e2e.issue' => 'AUX-132', 'user.orbit.e2e.attempt' => $attempt->value],
            $commands,
            network: false,
            presentNames: [$target->instance('app-prod-2')],
        );

        $result = releaserForTest($paths)->release(new TopologyRequest('AUX-132', $worktree));

        expect($result['released'])
            ->toBe([
                'stopped:'.$target->instance('app-prod-2'),
                'deleted:'.$target->instance('app-prod-2'),
            ])
            ->and($result['already_absent'])
            ->toBe([
                $target->instance('app-prod'),
                $target->instance('app-dev'),
                $target->instance('gateway'),
                $target->network(),
            ])
            ->and($state->hasAttempt(AttemptPurpose::Discovery))
            ->toBeFalse();
    });

    it('releases a current extended lease without a topology and retains its target across partial failure', function (): void {
        $worktree = temporaryPath('orbit-release-lease-target-', 4);
        mkdir($worktree, 0700);
        $paths = new StatePaths(temporaryPath('orbit-release-host-', 4));
        $attempt = new AttemptId(str_repeat('a', 32));
        $proof = new AttemptId(str_repeat('b', 32));
        $target = TopologyTarget::feature('AUX-7', $attempt, TopologyRecipe::extendedAppProd());
        $state = IssueState::forWorktree('AUX-7', $worktree);
        $state->writeAttempt(
            $attempt,
            AttemptPurpose::Discovery,
            new OperationId(str_repeat('c', 32)),
            TopologyExtension::AppProd,
        );
        $state->writeAttempt($proof, AttemptPurpose::Proof, new OperationId(str_repeat('d', 32)));
        $proofLease = $state->attempt(AttemptPurpose::Proof);
        $commands = [];
        fakeReleaseHost(
            $target,
            ['user.orbit.e2e.issue' => 'AUX-7', 'user.orbit.e2e.attempt' => $attempt->value],
            $commands,
            failCommandContaining: 'network delete',
        );

        expect(fn () => releaserForTest($paths)->release(new TopologyRequest('AUX-7', $worktree)))
            ->toThrow(RuntimeException::class, 'injected cleanup failure')
            ->and($state->attempt(AttemptPurpose::Discovery)['extension'])
            ->toBe('app-prod')
            ->and($state->attempt(AttemptPurpose::Proof))
            ->toBe($proofLease);

        $commands = [];
        fakeReleaseHost(
            $target,
            ['user.orbit.e2e.issue' => 'AUX-7', 'user.orbit.e2e.attempt' => $attempt->value],
            $commands,
            presentNames: [],
        );
        $result = releaserForTest($paths)->release(new TopologyRequest('AUX-7', $worktree));

        expect($result['already_absent'])
            ->toContain(
                $target->instance('gateway'),
                $target->instance('app-dev'),
                $target->instance('app-prod'),
                $target->instance('app-prod-2'),
            )
            ->and($state->hasAttempt(AttemptPurpose::Discovery))
            ->toBeFalse()
            ->and($state->attempt(AttemptPurpose::Proof))
            ->toBe($proofLease);
    });

    it('uses a complete matching legacy topology and refuses an ambiguous legacy lease before Incus', function (): void {
        $completeWorktree = temporaryPath('orbit-release-complete-legacy-', 4);
        mkdir($completeWorktree, 0700);
        $attempt = new AttemptId(str_repeat('a', 32));
        $target = TopologyTarget::feature('AUX-7', $attempt, TopologyRecipe::extendedAppProd());
        $completeState = IssueState::forWorktree('AUX-7', $completeWorktree);
        $completeState->writeAttempt(
            $attempt,
            AttemptPurpose::Discovery,
            new OperationId(str_repeat('b', 32)),
            TopologyExtension::AppProd,
        );
        $completeState->writeTopology(extendedReleaseTopology($target, AttemptPurpose::Discovery));
        $leasePath = $completeWorktree.'/.e2e/'.IssueState::ATTEMPT;
        $legacy = json_decode((string) file_get_contents($leasePath), true, 8, JSON_THROW_ON_ERROR);
        unset($legacy['extension']);
        file_put_contents($leasePath, json_encode($legacy, JSON_THROW_ON_ERROR));
        $commands = [];
        fakeReleaseHost(
            $target,
            ['user.orbit.e2e.issue' => 'AUX-7', 'user.orbit.e2e.attempt' => $attempt->value],
            $commands,
        );

        expect(
            releaserForTest(new StatePaths(temporaryPath('orbit-release-host-', 4)))
                ->release(new TopologyRequest('AUX-7', $completeWorktree))['released'],
        )
            ->toContain('deleted:'.$target->instance('app-prod-2'));

        $ambiguousWorktree = temporaryPath('orbit-release-ambiguous-legacy-', 4);
        mkdir($ambiguousWorktree, 0700);
        $ambiguousState = IssueState::forWorktree('AUX-7', $ambiguousWorktree);
        $ambiguousState->writeAttempt($attempt, AttemptPurpose::Discovery, new OperationId(str_repeat('b', 32)));
        $ambiguousPath = $ambiguousWorktree.'/.e2e/'.IssueState::ATTEMPT;
        $ambiguous = json_decode((string) file_get_contents($ambiguousPath), true, 8, JSON_THROW_ON_ERROR);
        unset($ambiguous['extension']);
        file_put_contents($ambiguousPath, json_encode($ambiguous, JSON_THROW_ON_ERROR));
        $before = file_get_contents($ambiguousPath);
        $ambiguousCommands = [];
        Process::fake(function (PendingProcess $process) use (&$ambiguousCommands) {
            $ambiguousCommands[] = $process->command;

            return Process::result('[]');
        });

        expect(fn () => releaserForTest(new StatePaths(temporaryPath('orbit-release-host-', 4)))
            ->release(new TopologyRequest('AUX-7', $ambiguousWorktree)))
            ->toThrow(RuntimeException::class, 'extension target is ambiguous')
            ->and(file_get_contents($ambiguousPath))
            ->toBe($before)
            ->and($ambiguousCommands)
            ->toBe([]);
    });

    it('recovers one exact legacy target idempotently and refuses stale, conflicting, or unsafe none input', function (): void {
        $worktree = temporaryPath('orbit-release-recovery-', 4);
        mkdir($worktree, 0700);
        $paths = new StatePaths(temporaryPath('orbit-release-host-', 4));
        $attempt = new AttemptId(str_repeat('a', 32));
        $target = TopologyTarget::feature('AUX-7', $attempt, TopologyRecipe::extendedAppProd());
        $state = IssueState::forWorktree('AUX-7', $worktree);
        $state->writeAttempt($attempt, AttemptPurpose::Discovery, new OperationId(str_repeat('b', 32)));
        $leasePath = $worktree.'/.e2e/'.IssueState::ATTEMPT;
        $legacy = json_decode((string) file_get_contents($leasePath), true, 8, JSON_THROW_ON_ERROR);
        unset($legacy['extension']);
        file_put_contents($leasePath, json_encode($legacy, JSON_THROW_ON_ERROR));
        $commands = [];
        fakeReleaseHost(
            $target,
            ['user.orbit.e2e.issue' => 'AUX-7', 'user.orbit.e2e.attempt' => $attempt->value],
            $commands,
            network: false,
            presentNames: [$target->instance('app-prod-2')],
        );

        expect(fn () => releaserForTest($paths)->release(
            new TopologyRequest('AUX-7', $worktree),
            AttemptPurpose::Discovery,
            LeaseTargetRecovery::fromOptions('none', $attempt->value),
        ))
            ->toThrow(RuntimeException::class, 'conflicts with the exact app-prod-2 VM')
            ->and($state->leaseHasExtension(AttemptPurpose::Discovery))
            ->toBeFalse()
            ->and(array_filter($commands, static fn (string $command): bool => str_starts_with($command, 'delete')))
            ->toBe([]);

        $staleCommands = [];
        Process::fake(function (PendingProcess $process) use (&$staleCommands) {
            $staleCommands[] = $process->command;

            return Process::result('[]');
        });
        expect(fn () => releaserForTest($paths)->release(
            new TopologyRequest('AUX-7', $worktree),
            AttemptPurpose::Discovery,
            LeaseTargetRecovery::fromOptions('app-prod', str_repeat('c', 32)),
        ))
            ->toThrow(RuntimeException::class, 'does not match the active lease')
            ->and($staleCommands)
            ->toBe([]);

        $commands = [];
        fakeReleaseHost(
            $target,
            ['user.orbit.e2e.issue' => 'AUX-7', 'user.orbit.e2e.attempt' => str_repeat('f', 32)],
            $commands,
        );
        expect(fn () => releaserForTest($paths)->release(
            new TopologyRequest('AUX-7', $worktree),
            AttemptPurpose::Discovery,
            LeaseTargetRecovery::fromOptions('app-prod', $attempt->value),
        ))
            ->toThrow(RuntimeException::class, 'ownership does not match the issue attempt');
        $recovered = $state->attempt(AttemptPurpose::Discovery);
        expect($recovered)
            ->toMatchArray($legacy)
            ->and($recovered['extension'])
            ->toBe('app-prod');

        $conflictCommands = [];
        Process::fake(function (PendingProcess $process) use (&$conflictCommands) {
            $conflictCommands[] = $process->command;

            return Process::result('[]');
        });
        expect(fn () => releaserForTest($paths)->release(
            new TopologyRequest('AUX-7', $worktree),
            AttemptPurpose::Discovery,
            LeaseTargetRecovery::fromOptions('none', $attempt->value),
        ))
            ->toThrow(RuntimeException::class, 'cannot replace')
            ->and($conflictCommands)
            ->toBe([]);

        $commands = [];
        fakeReleaseHost(
            $target,
            ['user.orbit.e2e.issue' => 'AUX-7', 'user.orbit.e2e.attempt' => $attempt->value],
            $commands,
        );
        $result = releaserForTest($paths)->release(
            new TopologyRequest('AUX-7', $worktree),
            AttemptPurpose::Discovery,
            LeaseTargetRecovery::fromOptions('app-prod', $attempt->value),
        );
        expect($result['released'])
            ->toContain('deleted:'.$target->instance('app-prod-2'))
            ->and($state->hasAttempt(AttemptPurpose::Discovery))
            ->toBeFalse();
    });

    it('releases candidate convergence without releasing proof or discovery', function (): void {
        $worktree = temporaryPath('orbit-release-worktree-', 4);
        mkdir($worktree, 0700);
        $paths = new StatePaths(temporaryPath('orbit-release-host-', 4));
        $discovery = new AttemptId(str_repeat('a', 32));
        $proof = new AttemptId(str_repeat('b', 32));
        $candidate = new AttemptId(str_repeat('c', 32));
        $target = TopologyTarget::feature('AUX-7', $candidate);
        $state = IssueState::forWorktree('AUX-7', $worktree);
        $state->writeAttempt($discovery, AttemptPurpose::Discovery, new OperationId(str_repeat('d', 32)));
        $state->writeAttempt($proof, AttemptPurpose::Proof, new OperationId(str_repeat('e', 32)));
        $state->writeAttempt($candidate, AttemptPurpose::CandidateConvergence, new OperationId(str_repeat('f', 32)));
        $commands = [];
        fakeReleaseHost(
            $target,
            ['user.orbit.e2e.issue' => 'AUX-7', 'user.orbit.e2e.attempt' => $candidate->value],
            $commands,
        );

        $result = releaserForTest($paths)->release(
            new TopologyRequest('AUX-7', $worktree),
            AttemptPurpose::CandidateConvergence,
        );

        expect($result['purpose'])
            ->toBe('candidate-convergence')
            ->and($state->hasAttempt(AttemptPurpose::CandidateConvergence))
            ->toBeFalse()
            ->and($state->hasAttempt(AttemptPurpose::Proof))
            ->toBeTrue()
            ->and($state->hasAttempt(AttemptPurpose::Discovery))
            ->toBeTrue();
    });

    it('keeps the successful proof commit pin when exact closeout cleanup releases machines', function (): void {
        $worktree = temporaryPath('orbit-release-worktree-', 4);
        mkdir($worktree, 0700);
        file_put_contents($worktree.'/.gitignore', "/.e2e/\n");
        Process::run(['git', '-C', $worktree, 'init', '--quiet', '-b', 'codex/aux-99-release'])->throw();
        Process::run(['git', '-C', $worktree, 'config', 'user.email', 'orbit@example.test'])->throw();
        Process::run(['git', '-C', $worktree, 'config', 'user.name', 'Orbit'])->throw();
        Process::run(['git', '-C', $worktree, 'add', '.'])->throw();
        Process::run(['git', '-C', $worktree, 'commit', '--quiet', '-m', 'proved'])->throw();
        $repository = new GitRepository($worktree);
        $proved = $repository->commit();
        $attempt = new AttemptId(str_repeat('a', 32));
        $target = TopologyTarget::feature('AUX-99', $attempt);
        $state = IssueState::forWorktree('AUX-99', $worktree);
        $state->writeAttempt($attempt, AttemptPurpose::Proof, new OperationId(str_repeat('c', 32)));
        $state->writeProof([
            'status' => 'proved',
            'attempt_id' => $attempt->value,
            'manifest_sha256' => str_repeat('d', 64),
        ]);
        $repository->pinProof('AUX-99', $attempt, $proved);
        $commands = [];
        fakeReleaseHost(
            $target,
            ['user.orbit.e2e.issue' => 'AUX-99', 'user.orbit.e2e.attempt' => $attempt->value],
            $commands,
        );

        releaserForTest(new StatePaths(temporaryPath('orbit-release-host-', 4)))
            ->releaseExact(new TopologyRequest('AUX-99', $worktree), [
                AttemptPurpose::Proof->value => $attempt,
            ]);
        $output = [];
        $exitCode = 0;
        exec(implode(' ', array_map(escapeshellarg(...), [
            'git',
            '-C',
            $worktree,
            'show-ref',
            '--verify',
            'refs/orbit/e2e-proof/aux-99/'.$attempt->value,
        ])).' 2>/dev/null', $output, $exitCode);

        expect($exitCode)->toBe(0);
    });

    it('refuses an exact cleanup set with a replaced identity before any Incus, Git, or lease mutation', function (): void {
        $worktree = temporaryPath('orbit-release-exact-replaced-', 4);
        mkdir($worktree, 0700);
        file_put_contents($worktree.'/.gitignore', "/.e2e/\n");
        Process::run(['git', '-C', $worktree, 'init', '--quiet', '-b', 'codex/aux-99-exact'])->throw();
        Process::run(['git', '-C', $worktree, 'config', 'user.email', 'orbit@example.test'])->throw();
        Process::run(['git', '-C', $worktree, 'config', 'user.name', 'Orbit'])->throw();
        Process::run(['git', '-C', $worktree, 'add', '.'])->throw();
        Process::run(['git', '-C', $worktree, 'commit', '--quiet', '-m', 'proved'])->throw();
        $repository = new GitRepository($worktree);
        $proved = $repository->commit();
        $capturedProof = new AttemptId(str_repeat('a', 32));
        $replacementProof = new AttemptId(str_repeat('b', 32));
        $discovery = new AttemptId(str_repeat('c', 32));
        $state = IssueState::forWorktree('AUX-99', $worktree);
        $state->writeAttempt(
            $replacementProof,
            AttemptPurpose::Proof,
            new OperationId(str_repeat('d', 32)),
            TopologyExtension::AppProd,
        );
        $state->writeTopology(extendedReleaseTopology(
            TopologyTarget::feature('AUX-99', $replacementProof, TopologyRecipe::extendedAppProd()),
            AttemptPurpose::Proof,
        ));
        $state->writeAttempt($discovery, AttemptPurpose::Discovery, new OperationId(str_repeat('e', 32)));
        $state->writeProof([
            'status' => 'proved',
            'attempt_id' => $capturedProof->value,
            'manifest_sha256' => str_repeat('f', 64),
        ]);
        $repository->pinProof('AUX-99', $capturedProof, $proved);
        $leasePath = $worktree.'/.e2e/'.IssueState::PROOF_ATTEMPT;
        $topologyPath = $worktree.'/.e2e/'.IssueState::PROOF_TOPOLOGY;
        $lease = file_get_contents($leasePath);
        $topology = file_get_contents($topologyPath);
        Process::fake();

        expect(fn () => releaserForTest(new StatePaths(temporaryPath('orbit-release-host-', 4)))
            ->releaseExact(new TopologyRequest('AUX-99', $worktree), [
                AttemptPurpose::Discovery->value => $discovery,
                AttemptPurpose::Proof->value => $capturedProof,
            ]))
            ->toThrow(RuntimeException::class, "was replaced by {$replacementProof->value}")
            ->and(file_get_contents($leasePath))
            ->toBe($lease)
            ->and(file_get_contents($topologyPath))
            ->toBe($topology)
            ->and($state->attemptId(AttemptPurpose::Discovery)->value)
            ->toBe($discovery->value);
        Process::assertNothingRan();

        $output = [];
        $exitCode = 0;
        exec(implode(' ', array_map(escapeshellarg(...), [
            'git',
            '-C',
            $worktree,
            'show-ref',
            '--verify',
            'refs/orbit/e2e-proof/aux-99/'.$capturedProof->value,
        ])).' 2>/dev/null', $output, $exitCode);
        expect($exitCode)->toBe(0);
    });

    it('refuses an exact cleanup set with an absent identity before mutating an unchanged attempt', function (): void {
        $worktree = temporaryPath('orbit-release-exact-absent-', 4);
        mkdir($worktree, 0700);
        $discovery = new AttemptId(str_repeat('a', 32));
        $absentProof = new AttemptId(str_repeat('b', 32));
        $state = IssueState::forWorktree('AUX-99', $worktree);
        $state->writeAttempt($discovery, AttemptPurpose::Discovery, new OperationId(str_repeat('c', 32)));
        $leasePath = $worktree.'/.e2e/'.IssueState::ATTEMPT;
        $lease = file_get_contents($leasePath);
        Process::fake();

        expect(fn () => releaserForTest(new StatePaths(temporaryPath('orbit-release-host-', 4)))
            ->releaseExact(new TopologyRequest('AUX-99', $worktree), [
                AttemptPurpose::Discovery->value => $discovery,
                AttemptPurpose::Proof->value => $absentProof,
            ]))
            ->toThrow(RuntimeException::class, "Captured proof attempt {$absentProof->value} is absent")
            ->and(file_get_contents($leasePath))
            ->toBe($lease)
            ->and($state->attemptId(AttemptPurpose::Discovery)->value)
            ->toBe($discovery->value);
        Process::assertNothingRan();
    });

    it('refuses a VM that another attempt owns and names an absent attempt', function () {
        $worktree = temporaryPath('orbit-release-worktree-', 4);
        mkdir($worktree, 0700);
        $paths = new StatePaths(temporaryPath('orbit-release-host-', 4));
        $attempt = new AttemptId(str_repeat('a', 32));
        $target = TopologyTarget::feature('TST-12', $attempt);
        $commands = [];

        expect(fn () => releaserForTest($paths)->release(new TopologyRequest('TST-12', $worktree)))
            ->toThrow(RuntimeException::class, 'TST-12 has no active attempt.');

        IssueState::forWorktree('TST-12', $worktree)
            ->writeAttempt($attempt, AttemptPurpose::Discovery, new OperationId(str_repeat('c', 32)));
        fakeReleaseHost(
            $target,
            ['user.orbit.e2e.issue' => 'TST-12', 'user.orbit.e2e.attempt' => str_repeat('f', 32)],
            $commands,
        );

        expect(fn () => releaserForTest($paths)->release(new TopologyRequest('TST-12', $worktree)))
            ->toThrow(RuntimeException::class, 'ownership does not match the issue attempt')
            ->and(array_filter($commands, static fn (string $command): bool => str_starts_with($command, 'delete')))
            ->toBe([])
            ->and(IssueState::forWorktree('TST-12', $worktree)->hasAttempt())
            ->toBeTrue();
    });
});
