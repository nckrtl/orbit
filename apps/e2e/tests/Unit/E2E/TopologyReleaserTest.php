<?php

declare(strict_types=1);

use App\E2E\Git\GitRepository;
use App\E2E\IncusHost;
use App\E2E\IncusNetworkLifecycle;
use App\E2E\IssueState;
use App\E2E\OrphanNetworkSweep;
use App\E2E\State\StatePaths;
use App\E2E\TopologyReleaser;
use App\E2E\Value\AttemptId;
use App\E2E\Value\AttemptPurpose;
use App\E2E\Value\FeatureTopology;
use App\E2E\Value\LaravelRelease;
use App\E2E\Value\OperationId;
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
 * @mago-expect lint:cyclomatic-complexity The fake answers each Incus read and mutation kind.
 *
 * @param array<string, string> $metadata
 * @param list<string> $commands
 */
function fakeReleaseHost(
    TopologyTarget $target,
    array $metadata,
    array &$commands,
    bool $network = true,
    ?array $presentNames = null,
): void {
    $present = $presentNames ?? array_map($target->instance(...), $target->recipe->nodeKeys());
    Process::fake(function (PendingProcess $process) use ($target, $metadata, &$commands, &$present, &$network) {
        $command = $process->command;
        assert(is_array($command));
        $commands[] = implode(' ', array_slice($command, 3));
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
        $target,
        $purpose,
        $generation,
        $target->network(),
        array_combine($target->recipe->nodeKeys(), array_map($target->instance(...), $target->recipe->nodeKeys())),
        new SourceState(str_repeat('a', 40), str_repeat('a', 40)),
        new VerificationReport(true, ['ready' => verificationProbeFixture(probe: 'ready')]),
        construction: TopologyConstructionInputs::create(
            $target,
            $generation,
            2,
            TopologyExtension::AppProd,
            str_repeat('b', 64),
        ),
    );
}

function releaserForTest(StatePaths $paths): TopologyReleaser
{
    $host = new IncusHost;
    $operation = new OperationId(str_repeat('b', 32));

    return new TopologyReleaser(
        $host,
        new IncusNetworkLifecycle($host),
        $paths,
        $operation,
        new OrphanNetworkSweep($host, new IncusNetworkLifecycle($host), $paths, $operation),
    );
}

describe('TopologyReleaser', function () {
    beforeEach(function () {
        $container = new Container;
        $container->instance(ProcessFactory::class, new ProcessFactory);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($container);
    });

    it('stops and deletes the exact VMs and network, drops the lease, and keeps the proof', function () {
        $worktree = temporaryPath('orbit-release-worktree-', 4);
        mkdir($worktree, 0700);
        $paths = new StatePaths(temporaryPath('orbit-release-host-', 4));
        $attempt = new AttemptId(str_repeat('a', 32));
        $target = TopologyTarget::feature('TST-12', $attempt);
        $state = IssueState::forWorktree('TST-12', $worktree);
        $state->writeAttempt($attempt, AttemptPurpose::Proof, new OperationId(str_repeat('c', 32)));
        $state->writeProof(['status' => 'proved', 'attempt_id' => $attempt->value]);
        $commands = [];
        fakeReleaseHost(
            $target,
            ['user.orbit.e2e.issue' => 'TST-12', 'user.orbit.e2e.attempt' => $attempt->value],
            $commands,
        );

        $result = releaserForTest($paths)->release(new TopologyRequest('TST-12', $worktree));

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
            ->toBe('proved')
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
        $state->writeAttempt($attempt, AttemptPurpose::Discovery, new OperationId(str_repeat('c', 32)));
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
        $state->writeAttempt($attempt, AttemptPurpose::Discovery, new OperationId(str_repeat('c', 32)));
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

    it('removes the successful proof commit pin when that proof is released', function (): void {
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
            ->release(
                new TopologyRequest('AUX-99', $worktree),
                AttemptPurpose::Proof,
            );
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

        expect($exitCode)->not->toBe(0);
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
