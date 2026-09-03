<?php

declare(strict_types=1);

use App\E2E\Git\GitRepository;
use App\E2E\IncusHost;
use App\E2E\IncusNetworkLifecycle;
use App\E2E\IssueState;
use App\E2E\OrphanNetworkSweep;
use App\E2E\PreparedStateFingerprint;
use App\E2E\State\AtomicJsonStore;
use App\E2E\State\OperationLock;
use App\E2E\State\StatePaths;
use App\E2E\TopologyReleaser;
use App\E2E\TopologySnapshotManifestStore;
use App\E2E\TopologySnapshotPromoter;
use App\E2E\TopologySnapshotPromotionStore;
use App\E2E\TopologyVerifier;
use App\E2E\Value\AttemptId;
use App\E2E\Value\AttemptPurpose;
use App\E2E\Value\CandidateConvergenceResult;
use App\E2E\Value\ConvergenceReport;
use App\E2E\Value\FeatureTopology;
use App\E2E\Value\ObservedPhpInputs;
use App\E2E\Value\OperationId;
use App\E2E\Value\ProofEquivalenceReport;
use App\E2E\Value\ProofEquivalenceResult;
use App\E2E\Value\ProofFixtures;
use App\E2E\Value\ProofInputManifest;
use App\E2E\Value\ProofPlan;
use App\E2E\Value\SourceState;
use App\E2E\Value\TopologyProfile;
use App\E2E\Value\TopologyRequest;
use App\E2E\Value\TopologySnapshotIdentity;
use App\E2E\Value\TopologyTarget;
use App\E2E\Value\VerificationReport;
use Illuminate\Container\Container;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Process\Factory as ProcessFactory;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Process;

require_once __DIR__.'/Support/TopologyFixtures.php';

beforeEach(function (): void {
    $container = new Container;
    $container->instance(ProcessFactory::class, new ProcessFactory);
    Facade::clearResolvedInstances();
    Facade::setFacadeApplication($container);
});

/**
 * A fixture repository whose `main` holds the proved candidate, a worktree
 * with a proved proof attempt under `.e2e/`, and a promoted old generation.
 *
 * @return array{root: string, worktree: string, paths: StatePaths, manifests: TopologySnapshotManifestStore, request: TopologyRequest, target: TopologyTarget, candidate: string, plan: ProofPlan}
 */
function promotableFixture(
    bool $mainHoldsCandidate = true,
    AttemptPurpose $purpose = AttemptPurpose::Proof,
    bool $observedInputs = false,
): array {
    $root = preparedTopologyRepository();
    $worktree = pinnedFeatureWorktree($root, 'promote');
    $candidate = new GitRepository($worktree)->commit();
    if ($mainHoldsCandidate) {
        Process::run(['git', '-C', $root, 'branch', '-f', 'main', $candidate])->throw();
    }
    $paths = new StatePaths(temporaryPath('orbit-promote-state-', 4));
    promoteDiscoveryGeneration($root, $paths);
    $manifests = new TopologySnapshotManifestStore(new AtomicJsonStore($paths), $paths, new IncusHost);
    $promoted = $manifests->promoted();
    assert($promoted !== null);

    $target = TopologyTarget::feature('NCK-123', new AttemptId(str_repeat('a', 32)));
    $state = IssueState::forWorktree('NCK-123', $worktree);
    $operation = new OperationId(str_repeat('b', 32));
    $state->writeAttempt($target->requireAttempt(), $purpose, $operation);
    $state->writeTopology(new FeatureTopology(
        $target,
        $purpose,
        $promoted,
        $target->network(),
        array_combine(TopologyProfile::ROLES, array_map($target->instance(...), TopologyProfile::ROLES)),
        new SourceState($candidate, $candidate, operationId: $operation->value),
        new VerificationReport(true, [
            'proof.verify' => [
                'passed' => true,
                'checked_at' => '2026-08-30T00:00:00Z',
                'expected' => 'healthy',
                'observed' => 'healthy',
                'evidence_ref' => 'incus://'.$target->instance('gateway').'/proof.verify',
            ],
        ]),
    ));
    $planPath = $worktree.'/proofs/NCK-123.json';
    mkdir(dirname($planPath), 0700, true);
    $planValue = [
        'setup' => [],
        'acceptance' => [[
            'id' => 'doctor',
            'node' => 'app-dev',
            'argv' => ['orbit', 'doctor'],
            'timeout_seconds' => 60,
        ]],
    ];
    if ($observedInputs) {
        $planValue['observed_inputs'] = true;
    }
    file_put_contents($planPath, json_encode($planValue, JSON_THROW_ON_ERROR));
    $plan = ProofPlan::fromFile($planPath);
    if ($purpose === AttemptPurpose::Proof) {
        $manifest = new ProofInputManifest(
            2,
            $candidate,
            $candidate,
            [],
            [[
                'path' => 'proofs/NCK-123.json',
                'classification' => 'proof-contract',
                'mode' => '100644',
                'blob' => str_repeat('d', 40),
            ]],
            'proofs/NCK-123.json',
            [],
            [],
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
        $state->writeProofInputManifest($manifest->fingerprint(), $manifest->toArray());
        $state->writeProof([
            'status' => 'proved',
            'issue' => 'NCK-123',
            'attempt_id' => $target->requireAttempt()->value,
            'candidate_sha' => $candidate,
            'plan_sha256' => $plan->fingerprint(),
            'manifest_sha256' => $manifest->fingerprint(),
            'actions' => [['id' => 'doctor', 'node' => 'app-dev', 'exit_code' => 0]],
            'recorded_at' => '2026-08-30T00:00:00Z',
        ]);
    }

    return [
        'root' => $root,
        'worktree' => $worktree,
        'paths' => $paths,
        'manifests' => $manifests,
        'request' => new TopologyRequest('NCK-123', $worktree),
        'target' => $target,
        'candidate' => $candidate,
        'plan' => $plan,
    ];
}

function promoterFor(
    string $root,
    StatePaths $paths,
    TopologySnapshotManifestStore $manifests,
): TopologySnapshotPromoter {
    $host = new IncusHost(pool: 'default');
    $operation = new OperationId(str_repeat('c', 32));

    return new TopologySnapshotPromoter(
        $host,
        new PreparedStateFingerprint(new GitRepository($root)),
        new TopologyVerifier($host, readinessTimeoutSeconds: 1, readinessPollIntervalMicroseconds: 0),
        $manifests,
        new TopologyReleaser(
            $host,
            new IncusNetworkLifecycle($host),
            $paths,
            $operation,
            new OrphanNetworkSweep($host, new IncusNetworkLifecycle($host), $paths, $operation),
        ),
        new OperationLock($paths),
        new OperationLock($paths),
        $paths,
        new GitRepository($root),
        $operation,
        TopologySnapshotIdentity::primary(),
        new TopologySnapshotPromotionStore(new AtomicJsonStore($paths)),
    );
}

/** @return array{fixture:array,proof_target:TopologyTarget,candidate_target:TopologyTarget,discovery_target:TopologyTarget,equivalence:ProofEquivalenceReport} */
function candidatePromotionFixture(): array
{
    $fixture = promotableFixture(observedInputs: true);
    $state = IssueState::forWorktree('NCK-123', $fixture['worktree']);
    $proof = $state->proof();
    assert($proof !== null);
    $proofTarget = $fixture['target'];

    $path = $fixture['worktree'].'/apps/cli/app/Unrelated.php';
    if (! is_dir(dirname($path))) {
        mkdir(dirname($path), 0700, true);
    }
    file_put_contents($path, "<?php\n\nfinal class Unrelated {}\n");
    Process::run(['git', '-C', $fixture['worktree'], 'add', 'apps/cli/app/Unrelated.php'])->throw();
    Process::run(['git', '-C', $fixture['worktree'], 'commit', '--quiet', '-m', 'Unrelated runtime'])->throw();
    $repository = new GitRepository($fixture['worktree']);
    $accepted = $repository->commit();
    Process::run(['git', '-C', $fixture['root'], 'branch', '-f', 'main', $accepted])->throw();

    $packages = array_fill_keys(ObservedPhpInputs::PACKAGES, '8.5.10-sury');
    $packages['php8.5-pcov'] = '1.0.12-sury';
    $runtime = static fn (string $role): array => [
        'role' => $role,
        'php_version' => '8.5.10',
        'fpm_version' => '8.5.10',
        'pcov_version' => '1.0.12',
        'package_versions' => $packages,
    ];
    $surface = static fn (string $role, string $type, string $id): array => [
        'role' => $role,
        'process_type' => $type,
        'processes' => [[
            'id' => str_repeat($id, 32),
            'started_at' => '2026-09-03T00:00:00.000001Z',
            'finished_at' => '2026-09-03T00:00:00.000002Z',
        ]],
        'paths' => ['apps/cli/orbit'],
    ];
    $surfaces = [
        $surface('app-dev', 'cli', '1'),
        $surface('gateway', 'cli', '2'),
        $surface('gateway', 'fpm', '3'),
    ];
    $manifest = new ProofInputManifest(
        2,
        $fixture['candidate'],
        $fixture['candidate'],
        [],
        [[
            'path' => 'proofs/NCK-123.json',
            'classification' => 'proof-contract',
            'mode' => '100644',
            'blob' => str_repeat('d', 40),
        ]],
        'proofs/NCK-123.json',
        [],
        [],
        new ObservedPhpInputs(
            [$runtime('app-dev'), $runtime('gateway')],
            ['setup' => $surfaces, 'acceptance' => $surfaces],
        ),
        [
            'static_classification' => true,
            'proof_contract' => true,
            'checkout_literals' => true,
            'observed_processes' => true,
            'observed_paths' => true,
            'pcov_cleanup' => true,
        ],
    );
    $state->writeProofInputManifest($manifest->fingerprint(), $manifest->toArray());
    $proof['manifest_sha256'] = $manifest->fingerprint();
    $state->writeProof($proof);

    $equivalence = new ProofEquivalenceReport(
        $fixture['candidate'],
        $accepted,
        $fixture['candidate'],
        $fixture['plan']->fingerprint(),
        $manifest->fingerprint(),
        ProofEquivalenceResult::Equivalent,
        [[
            'path' => 'apps/cli/app/Unrelated.php',
            'previous_path' => null,
            'change' => 'added',
            'classification' => 'unrelated-runtime',
        ]],
        'candidate-convergence',
        'run-candidate-convergence',
        [],
        '2026-09-03T00:00:00Z',
    );
    $state->writeEquivalence($equivalence->fingerprint(), $equivalence->toArray());

    $candidateTarget = TopologyTarget::feature('NCK-123', new AttemptId(str_repeat('c', 32)));
    $proofTopology = $state->requireTopology(AttemptPurpose::Proof);
    $verification = new VerificationReport(true, [
        'candidate.verify' => [
            'passed' => true,
            'checked_at' => '2026-09-03T00:00:00Z',
            'expected' => 'healthy',
            'observed' => 'healthy',
            'evidence_ref' => 'incus://'.$candidateTarget->instance('gateway').'/candidate.verify',
        ],
    ]);
    $state->writeAttempt(
        $candidateTarget->requireAttempt(),
        AttemptPurpose::CandidateConvergence,
        new OperationId(str_repeat('c', 32)),
    );
    $state->writeTopology(new FeatureTopology(
        $candidateTarget,
        AttemptPurpose::CandidateConvergence,
        $proofTopology->generation,
        $candidateTarget->network(),
        array_combine(
            TopologyProfile::ROLES,
            array_map($candidateTarget->instance(...), TopologyProfile::ROLES),
        ),
        new SourceState($accepted, $accepted, operationId: str_repeat('c', 32)),
        $verification,
    ));
    $state->writeCandidateConvergence(new CandidateConvergenceResult(
        'converged',
        'NCK-123',
        $candidateTarget->requireAttempt(),
        $accepted,
        $repository->tree($accepted),
        $equivalence->fingerprint(),
        ConvergenceReport::successful(['gateway' => true, 'app-dev' => true, 'app-prod' => true]),
        $verification,
        null,
        '2026-09-03T00:00:00Z',
    ));

    $discoveryTarget = TopologyTarget::feature('NCK-123', new AttemptId(str_repeat('d', 32)));
    $state->writeAttempt(
        $discoveryTarget->requireAttempt(),
        AttemptPurpose::Discovery,
        new OperationId(str_repeat('d', 32)),
    );
    $state->writeTopology(new FeatureTopology(
        $discoveryTarget,
        AttemptPurpose::Discovery,
        $proofTopology->generation,
        $discoveryTarget->network(),
        array_combine(
            TopologyProfile::ROLES,
            array_map($discoveryTarget->instance(...), TopologyProfile::ROLES),
        ),
        $proofTopology->source,
        $proofTopology->verification,
    ));

    return compact('fixture', 'proofTarget', 'candidateTarget', 'discoveryTarget', 'equivalence');
}

/**
 * A stateful Incus fake: the topology snapshot, proof, and optional discovery
 * resources, mutated by every command that promotion and release issue.
 *
 * @param list<string> $events
 * @mago-expect lint:cyclomatic-complexity,excessive-parameter-list,halstead,kan-defect The fake maps one complete promotion process boundary.
 */
function fakePromotionHost(
    TopologyTarget $target,
    array &$events,
    ?string $failAt = null,
    ?array &$guestEvents = null,
    bool $failAssignments = false,
    ?TopologyTarget $discoveryTarget = null,
    ?TopologyTarget $retainedProofTarget = null,
): void {
    $topologySnapshot = TopologyTarget::topologySnapshot();
    $instances = [];
    $snapshots = [];
    foreach (TopologyProfile::ROLES as $role) {
        $instances[$topologySnapshot->instance($role)] = [
            'status' => 'Stopped',
            'config' => ['user.orbit.e2e.owner' => 'orbit-e2e', 'user.orbit.e2e.operation' => 'old-op'],
            'network' => $topologySnapshot->network(),
        ];
        $snapshots[$topologySnapshot->instance($role)] = ['main-'.$role];
        $instances[$target->instance($role)] = [
            'status' => 'Running',
            'config' => [
                'user.orbit.e2e.owner' => 'orbit-e2e',
                'user.orbit.e2e.issue' => $target->issue,
                'user.orbit.e2e.attempt' => $target->requireAttempt()->value,
                'user.orbit.e2e.operation' => str_repeat('b', 32),
                'user.orbit.e2e.generation' => 'old',
            ],
            'network' => $target->network(),
        ];
        $snapshots[$target->instance($role)] = [];
        if ($discoveryTarget !== null) {
            $instances[$discoveryTarget->instance($role)] = [
                'status' => 'Running',
                'config' => [
                    'user.orbit.e2e.owner' => 'orbit-e2e',
                    'user.orbit.e2e.issue' => $discoveryTarget->issue,
                    'user.orbit.e2e.attempt' => $discoveryTarget->requireAttempt()->value,
                    'user.orbit.e2e.operation' => str_repeat('d', 32),
                    'user.orbit.e2e.generation' => 'old',
                ],
                'network' => $discoveryTarget->network(),
            ];
            $snapshots[$discoveryTarget->instance($role)] = [];
        }
        if ($retainedProofTarget !== null) {
            $instances[$retainedProofTarget->instance($role)] = [
                'status' => 'Running',
                'config' => [
                    'user.orbit.e2e.owner' => 'orbit-e2e',
                    'user.orbit.e2e.issue' => $retainedProofTarget->issue,
                    'user.orbit.e2e.attempt' => $retainedProofTarget->requireAttempt()->value,
                    'user.orbit.e2e.operation' => str_repeat('b', 32),
                    'user.orbit.e2e.generation' => 'old',
                ],
                'network' => $retainedProofTarget->network(),
            ];
            $snapshots[$retainedProofTarget->instance($role)] = [];
        }
    }
    $networks = [
        $topologySnapshot->network() => ['config' => [
            'user.orbit.e2e.owner' => 'orbit-e2e',
            'ipv4.address' => '10.232.1.1/24',
        ]],
        $target->network() => ['config' => [
            'user.orbit.e2e.owner' => 'orbit-e2e',
            'user.orbit.e2e.issue' => $target->issue,
            'user.orbit.e2e.attempt' => $target->requireAttempt()->value,
            'ipv4.address' => '10.232.2.1/24',
        ]],
    ];
    if ($discoveryTarget !== null) {
        $networks[$discoveryTarget->network()] = ['config' => [
            'user.orbit.e2e.owner' => 'orbit-e2e',
            'user.orbit.e2e.issue' => $discoveryTarget->issue,
            'user.orbit.e2e.attempt' => $discoveryTarget->requireAttempt()->value,
            'ipv4.address' => '10.232.3.1/24',
        ]];
    }
    if ($retainedProofTarget !== null) {
        $networks[$retainedProofTarget->network()] = ['config' => [
            'user.orbit.e2e.owner' => 'orbit-e2e',
            'user.orbit.e2e.issue' => $retainedProofTarget->issue,
            'user.orbit.e2e.attempt' => $retainedProofTarget->requireAttempt()->value,
            'ipv4.address' => '10.232.4.1/24',
        ]];
    }
    $realProcess = new ProcessFactory;
    $vm = static function (string $name, array $instance) use ($target): array {
        $role = str_ends_with($name, '-gateway')
            ? 'gateway'
            : (str_ends_with($name, '-app-dev') ? 'app-dev' : 'app-prod');

        return [
            'name' => $name,
            'type' => 'virtual-machine',
            'status' => $instance['status'],
            'status_code' => $instance['status'] === 'Running' ? 103 : 102,
            'config' => $instance['config'],
            'devices' => [
                'root' => ['pool' => 'default'],
                'eth0' => [
                    'network' => $instance['network'],
                    'hwaddr' => TopologyTarget::macFor($instance['network'], $role),
                    'ipv4.address' => TopologyTarget::ipv4For(2, $role),
                ],
            ],
        ];
    };

    Process::fake(function (PendingProcess $process) use (
        &$events,
        &$guestEvents,
        &$instances,
        &$snapshots,
        &$networks,
        $realProcess,
        $vm,
        $failAt,
        $failAssignments,
    ): ProcessResult {
        $command = $process->command;
        assert(is_array($command));
        if (($firewall = topologyFirewallResult($command)) !== null) {
            return $firewall;
        }
        $recorded = is_array($guestEvents) ? count($guestEvents) : 0;
        $guestOverride = static function (array $guest) use ($failAssignments): ProcessResult {
            if ($failAssignments && ($guest[1] ?? null) === 'role.assignments') {
                return Process::result('', 'required assignment missing', 1);
            }

            return pinnedWorktreeGuestCommandResult($guest);
        };
        if (($batch = pinnedWorktreeBatchResult($process, $guestEvents, $guestOverride)) !== null) {
            foreach (array_slice((array) $guestEvents, $recorded) as $guestEvent) {
                if (($guestEvent[6] ?? null) === 'rm') {
                    $events[] = 'exec:'.$guestEvent[4].':'.$guestEvent[6];
                }
            }

            return $batch;
        }
        if (($command[0] ?? null) === 'git') {
            return $realProcess
                ->path((string) ($process->path ?: getcwd()))
                ->input($process->input)
                ->run($command);
        }
        $name = static fn (?string $value): string => (string) preg_replace('/\A[^:]+:/', '', (string) $value);
        $action = $command[3] ?? null;
        if ($action === 'list') {
            $wanted = $name($command[4] ?? '');
            $listed = [];
            foreach ($instances as $instanceName => $instance) {
                if ($wanted === '' || $wanted === $instanceName) {
                    $listed[] = $vm($instanceName, $instance);
                }
            }

            return Process::result(json_encode($listed, JSON_THROW_ON_ERROR));
        }
        if ($action === 'network' && ($command[4] ?? null) === 'list') {
            $listed = [];
            foreach ($networks as $networkName => $network) {
                $usedBy = [];
                foreach ($instances as $instanceName => $instance) {
                    if ($instance['network'] === $networkName) {
                        $usedBy[] = '/1.0/instances/'.$instanceName;
                    }
                }
                $listed[] = ['name' => $networkName, 'config' => $network['config'], 'used_by' => $usedBy];
            }

            return Process::result(json_encode($listed, JSON_THROW_ON_ERROR));
        }
        if ($action === 'network' && ($command[4] ?? null) === 'delete') {
            $events[] = 'network-delete:'.$name($command[5] ?? '');
            unset($networks[$name($command[5] ?? '')]);

            return Process::result();
        }
        if ($action === 'snapshot' && ($command[4] ?? null) === 'list') {
            $instance = $name($command[5] ?? '');

            return Process::result(json_encode(array_map(
                static fn (string $snapshot): array => [
                    'name' => $snapshot,
                    'created_at' => '2026-01-01T00:00:00Z',
                    'config' => ['user.orbit.e2e.owner' => 'orbit-e2e'],
                ],
                $snapshots[$instance] ?? [],
            ), JSON_THROW_ON_ERROR));
        }
        if ($action === 'snapshot' && ($command[4] ?? null) === 'create') {
            $instance = $name($command[5] ?? '');
            $events[] = "snapshot:{$instance}/{$command[6]}";
            if ($failAt === 'snapshot' && str_ends_with($instance, 'app-prod-next')) {
                return Process::result('', 'controlled snapshot failure', 1);
            }
            $snapshots[$instance][] = $command[6];

            return Process::result();
        }
        if ($action === 'stop') {
            $instance = $name($command[4] ?? '');
            $events[] = 'stop:'.$instance;
            $instances[$instance]['status'] = 'Stopped';

            return Process::result();
        }
        if ($action === 'copy') {
            $source = $name($command[4] ?? '');
            $copy = $name($command[5] ?? '');
            $events[] = "copy:{$source}>{$copy}".(in_array('--instance-only', $command, true) ? ' instance-only' : '');
            $config = $instances[$source]['config'];
            $network = $instances[$source]['network'];
            foreach ($command as $index => $argument) {
                if ($argument === '--config' && str_starts_with((string) $command[$index + 1], 'user.')) {
                    [$key, $value] = explode('=', (string) $command[$index + 1], 2);
                    $config[$key] = $value;
                }
                if ($argument === '--device' && str_starts_with((string) $command[$index + 1], 'eth0,network=')) {
                    $network = substr((string) $command[$index + 1], strlen('eth0,network='));
                }
            }
            $instances[$copy] = ['status' => 'Stopped', 'config' => $config, 'network' => $network];
            $snapshots[$copy] = [];

            return Process::result();
        }
        if ($action === 'config' && ($command[4] ?? null) === 'unset') {
            $instance = $name($command[5] ?? '');
            $events[] = "unset:{$instance}/{$command[6]}";
            unset($instances[$instance]['config'][$command[6]]);

            return Process::result();
        }
        if ($action === 'delete') {
            $instance = $name($command[4] ?? '');
            $events[] = 'delete:'.$instance;
            unset($instances[$instance], $snapshots[$instance]);

            return Process::result();
        }
        if ($action === 'rename') {
            $from = $name($command[4] ?? '');
            $to = (string) $command[5];
            $events[] = "rename:{$from}>{$to}";
            $instances[$to] = $instances[$from];
            $snapshots[$to] = $snapshots[$from];
            unset($instances[$from], $snapshots[$from]);

            return Process::result();
        }

        throw new RuntimeException('Unexpected Incus command: '.implode(' ', $command));
    });
}

/** @mago-expect lint:cyclomatic-complexity,kan-defect The promotion test asserts the complete ordered command chain. */
describe('TopologySnapshotPromoter', function (): void {
    it('refuses a candidate with a missing required assignment before any mutation', function (): void {
        $fixture = promotableFixture();
        $events = [];
        fakePromotionHost($fixture['target'], $events, failAssignments: true);
        $old = $fixture['manifests']->promoted()?->toArray();

        expect(fn () => promoterFor($fixture['root'], $fixture['paths'], $fixture['manifests'])
            ->promote($fixture['request'], $fixture['plan']))
            ->toThrow(RuntimeException::class, 'required assignments');

        expect($events)
            ->toBe([])
            ->and($fixture['manifests']->promoted()?->toArray())
            ->toBe($old);
    });

    it('refuses a legacy promoted generation before any mutation', function (): void {
        $fixture = promotableFixture();
        $current = $fixture['manifests']->promoted();
        assert($current !== null);
        $legacy = $current->toArray();
        $legacy['schema'] = 4;
        $legacy['prepared_schema'] = 1;
        unset($legacy['topology']['assignments']);
        $fixture['manifests']->promote(\App\E2E\Value\TopologySnapshotGeneration::fromArray($legacy));
        $events = [];
        fakePromotionHost($fixture['target'], $events);

        expect(fn () => promoterFor($fixture['root'], $fixture['paths'], $fixture['manifests'])
            ->promote($fixture['request'], $fixture['plan']))
            ->toThrow(RuntimeException::class, 'legacy');

        expect($events)->toBe([]);
    });

    /** @mago-expect lint:kan-defect The promotion test asserts the complete ordered command chain. */
    it('promotes the proved topology and releases both proof and discovery', function (): void {
        $fixture = promotableFixture();
        $target = $fixture['target'];
        $discoveryTarget = TopologyTarget::feature('NCK-123', new AttemptId(str_repeat('d', 32)));
        $state = IssueState::forWorktree('NCK-123', $fixture['worktree']);
        $proofTopology = $state->requireTopology(AttemptPurpose::Proof);
        $state->writeAttempt(
            $discoveryTarget->requireAttempt(),
            AttemptPurpose::Discovery,
            new OperationId(str_repeat('d', 32)),
        );
        $state->writeTopology(new FeatureTopology(
            $discoveryTarget,
            AttemptPurpose::Discovery,
            $proofTopology->generation,
            $discoveryTarget->network(),
            array_combine(
                TopologyProfile::ROLES,
                array_map($discoveryTarget->instance(...), TopologyProfile::ROLES),
            ),
            $proofTopology->source,
            $proofTopology->verification,
        ));
        $topologySnapshot = TopologyTarget::topologySnapshot();
        $events = [];
        $guestEvents = [];
        fakePromotionHost($target, $events, null, $guestEvents, discoveryTarget: $discoveryTarget);
        $old = $fixture['manifests']->promoted();

        $result = promoterFor($fixture['root'], $fixture['paths'], $fixture['manifests'])
            ->promote($fixture['request'], $fixture['plan']);

        $fingerprint = topologyFinalPreparedFingerprint($fixture['root'], $fixture['candidate']);
        $expectedId = substr($fixture['candidate'], 0, 12).'-'.substr($fingerprint->value, 0, 12);
        $promoted = $fixture['manifests']->promoted();
        expect($result['state'])
            ->toBe('promoted')
            ->and($result['generation_id'])
            ->toBe($expectedId)
            ->and($result['main_sha'])
            ->toBe($fixture['candidate'])
            ->and($result['previous_generation_id'])
            ->toBe($old?->id)
            ->and($promoted?->id)
            ->toBe($expectedId)
            ->and($promoted?->snapshots)
            ->toBe(array_fill_keys(TopologyProfile::ROLES, 'main-'.$expectedId))
            ->and($promoted?->laravel->tag)
            ->toBe($old?->laravel->tag)
            ->and($promoted?->structuralFingerprint)
            ->toBe(new PreparedStateFingerprint(new GitRepository($fixture['root']))->forCommit(
                $fixture['candidate'],
            )->value)
            ->and($fixture['manifests']->recorded())
            ->toHaveCount(1)
            ->and(IssueState::forWorktree('NCK-123', $fixture['worktree'])->hasAttempt())
            ->toBeFalse();

        $expected = [];
        foreach (TopologyProfile::ROLES as $role) {
            $expected[] = 'exec:local:'.$target->instance($role).':rm';
        }
        foreach (TopologyProfile::ROLES as $role) {
            $expected[] = 'stop:'.$target->instance($role);
        }
        foreach (TopologyProfile::ROLES as $role) {
            $expected[] = 'copy:'.$target->instance($role).'>'.$topologySnapshot->instance($role).'-next instance-only';
        }
        foreach (TopologyProfile::ROLES as $role) {
            foreach (['issue', 'attempt', 'generation'] as $key) {
                $expected[] = 'unset:'.$topologySnapshot->instance($role)."-next/user.orbit.e2e.{$key}";
            }
        }
        foreach (TopologyProfile::ROLES as $role) {
            $expected[] = 'snapshot:'.$topologySnapshot->instance($role)."-next/main-{$expectedId}";
        }
        foreach (TopologyProfile::ROLES as $role) {
            $expected[] = 'delete:'.$topologySnapshot->instance($role);
            $expected[] = 'rename:'.$topologySnapshot->instance($role).'-next>'.$topologySnapshot->instance($role);
        }
        foreach (array_reverse(TopologyProfile::ROLES) as $role) {
            $expected[] = 'delete:'.$target->instance($role);
        }
        $expected[] = 'network-delete:'.$target->network();
        foreach (TopologyProfile::ROLES as $role) {
            $expected[] = 'stop:'.$discoveryTarget->instance($role);
        }
        foreach (array_reverse(TopologyProfile::ROLES) as $role) {
            $expected[] = 'delete:'.$discoveryTarget->instance($role);
        }
        $expected[] = 'network-delete:'.$discoveryTarget->network();
        $removals = array_values(array_filter(
            $guestEvents,
            static fn (array $event): bool => in_array('rm', $event, true),
        ));
        expect($removals)->toHaveCount(3)->and($removals[0])->toContain(ProofFixtures::GUEST_DIRECTORY);

        expect($events)->toBe($expected);
    });

    it('promotes retained proof for a different accepted SHA with equivalent recorded inputs', function (): void {
        $fixture = promotableFixture();
        $state = IssueState::forWorktree('NCK-123', $fixture['worktree']);
        $proof = $state->proof();
        assert($proof !== null && is_string($proof['manifest_sha256'] ?? null));
        mkdir($fixture['worktree'].'/docs', 0700, true);
        file_put_contents($fixture['worktree'].'/docs/correction.md', "correction\n");
        Process::run(['git', '-C', $fixture['worktree'], 'add', 'docs/correction.md'])->throw();
        Process::run(['git', '-C', $fixture['worktree'], 'commit', '--quiet', '-m', 'docs correction'])->throw();
        $accepted = new GitRepository($fixture['worktree'])->commit();
        Process::run(['git', '-C', $fixture['root'], 'branch', '-f', 'main', $accepted])->throw();
        $equivalence = new ProofEquivalenceReport(
            $fixture['candidate'],
            $accepted,
            $fixture['candidate'],
            $fixture['plan']->fingerprint(),
            $proof['manifest_sha256'],
            ProofEquivalenceResult::Equivalent,
            [[
                'path' => 'docs/correction.md',
                'previous_path' => null,
                'change' => 'added',
                'classification' => 'non-runtime',
            ]],
            'retained-proof',
            'review-exact-head',
            [],
            '2026-09-02T10:00:00Z',
        );
        $state->writeEquivalence($equivalence->fingerprint(), $equivalence->toArray());
        $events = [];
        fakePromotionHost($fixture['target'], $events);

        $result = promoterFor($fixture['root'], $fixture['paths'], $fixture['manifests'])
            ->promote($fixture['request'], $fixture['plan']);
        $lineage = new TopologySnapshotPromotionStore(new AtomicJsonStore($fixture['paths']))
            ->find($result['generation_id']);

        expect($result)
            ->toMatchArray([
                'promotion_path' => 'retained-proof',
                'proved_sha' => $fixture['candidate'],
                'accepted_sha' => $accepted,
                'merged_sha' => $accepted,
                'equivalence_sha256' => $equivalence->fingerprint(),
            ])
            ->and($lineage)
            ->toMatchArray([
                'proved_sha' => $fixture['candidate'],
                'accepted_sha' => $accepted,
                'merged_sha' => $accepted,
                'runtime_fingerprint' => $result['runtime_fingerprint'],
            ]);
    });

    it('promotes the verified candidate topology and releases candidate, retained proof, and discovery', function (): void {
        $candidate = candidatePromotionFixture();
        $fixture = $candidate['fixture'];
        $events = [];
        $guestEvents = [];
        fakePromotionHost(
            $candidate['candidateTarget'],
            $events,
            guestEvents: $guestEvents,
            discoveryTarget: $candidate['discoveryTarget'],
            retainedProofTarget: $candidate['proofTarget'],
        );

        $result = promoterFor($fixture['root'], $fixture['paths'], $fixture['manifests'])
            ->promote($fixture['request'], $fixture['plan']);

        $lineage = new TopologySnapshotPromotionStore(new AtomicJsonStore($fixture['paths']))
            ->find($result['generation_id']);
        $candidatePrefix = 'copy:'.$candidate['candidateTarget']->instance('gateway').'>';
        $proofPrefix = 'copy:'.$candidate['proofTarget']->instance('gateway').'>';
        $eventLog = implode("\n", $events);
        $expectedReleased = [];
        foreach ([$candidate['candidateTarget'], $candidate['discoveryTarget'], $candidate['proofTarget']] as $target) {
            foreach (array_reverse(TopologyProfile::ROLES) as $role) {
                $expectedReleased[] = 'deleted:'.$target->instance($role);
            }
            $expectedReleased[] = 'deleted:'.$target->network();
        }

        expect($result)
            ->toMatchArray([
                'promotion_path' => 'candidate-convergence',
                'attempt_id' => $candidate['candidateTarget']->requireAttempt()->value,
                'proved_sha' => $fixture['candidate'],
                'accepted_sha' => new GitRepository($fixture['worktree'])->commit(),
                'equivalence_sha256' => $candidate['equivalence']->fingerprint(),
            ])
            ->and($result['released'])
            ->toContain(...$expectedReleased)
            ->and($eventLog)
            ->toContain($candidatePrefix)
            ->not
            ->toContain($proofPrefix)
            ->and($lineage)
            ->toMatchArray([
                'promotion_path' => 'candidate-convergence',
                'proved_sha' => $fixture['candidate'],
                'accepted_sha' => $result['accepted_sha'],
                'equivalence_sha256' => $candidate['equivalence']->fingerprint(),
            ])
            ->and(IssueState::forWorktree('NCK-123', $fixture['worktree'])->hasAttempt())
            ->toBeFalse();
    });

    it('refuses candidate promotion without complete convergence evidence before any mutation', function (): void {
        $candidate = candidatePromotionFixture();
        $fixture = $candidate['fixture'];
        $state = IssueState::forWorktree('NCK-123', $fixture['worktree']);
        $topology = $state->requireTopology(AttemptPurpose::CandidateConvergence);
        $failedVerification = new VerificationReport(false, [
            'candidate.verify' => [
                'passed' => false,
                'checked_at' => '2026-09-03T00:00:00Z',
                'expected' => 'healthy',
                'observed' => 'failed',
                'evidence_ref' => 'incus://'.$topology->target->instance('gateway').'/candidate.verify',
            ],
        ]);
        $state->writeCandidateConvergence(new CandidateConvergenceResult(
            'diagnosis',
            'NCK-123',
            $topology->attempt,
            $topology->source->hostSha,
            new GitRepository($fixture['worktree'])->tree($topology->source->hostSha),
            $candidate['equivalence']->fingerprint(),
            null,
            $failedVerification,
            'candidate convergence failed',
            '2026-09-03T00:00:00Z',
        ));
        $events = [];
        fakePromotionHost(
            $candidate['candidateTarget'],
            $events,
            discoveryTarget: $candidate['discoveryTarget'],
            retainedProofTarget: $candidate['proofTarget'],
        );

        expect(fn () => promoterFor($fixture['root'], $fixture['paths'], $fixture['manifests'])
            ->promote($fixture['request'], $fixture['plan']))
            ->toThrow(RuntimeException::class, 'Candidate-convergence evidence is incomplete')
            ->and($events)
            ->toBe([]);
    });

    it('discards the copies and keeps the topology snapshot when the snapshot fails before the swap', function (): void {
        $fixture = promotableFixture();
        $events = [];
        fakePromotionHost($fixture['target'], $events, failAt: 'snapshot');
        $old = $fixture['manifests']->promoted();

        expect(fn () => promoterFor($fixture['root'], $fixture['paths'], $fixture['manifests'])
            ->promote($fixture['request'], $fixture['plan']))
            ->toThrow(RuntimeException::class, 'Topology snapshot promotion failed before the swap');

        $deleted = array_values(array_filter($events, static fn (string $event): bool => str_starts_with(
            $event,
            'delete:',
        )));
        expect($deleted)
            ->toBe(array_map(
                static fn (string $role): string => (
                    'delete:'.TopologyTarget::topologySnapshot()->instance($role).'-next'
                ),
                TopologyProfile::ROLES,
            ))
            ->and($events)
            ->not
            ->toContain('delete:'.TopologyTarget::topologySnapshot()->instance('gateway'))
            ->and($fixture['manifests']->promoted()?->toArray())
            ->toBe($old?->toArray())
            ->and(IssueState::forWorktree('NCK-123', $fixture['worktree'])->hasAttempt())
            ->toBeTrue();
    });

    it('refuses a topology that is not proved without touching Incus', function (): void {
        $fixture = promotableFixture(purpose: AttemptPurpose::Discovery);
        $events = [];
        fakePromotionHost($fixture['target'], $events);

        expect(fn () => promoterFor($fixture['root'], $fixture['paths'], $fixture['manifests'])
            ->promote($fixture['request'], $fixture['plan']))
            ->toThrow(RuntimeException::class, 'is not proved');
        expect($events)->toBe([]);
        Process::assertDidntRun(fn (PendingProcess $process): bool => ($process->command[0] ?? null) === 'incus');
    });

    it('refuses a plan that mutates the topology without touching Incus', function (): void {
        $fixture = promotableFixture();
        $events = [];
        fakePromotionHost($fixture['target'], $events);
        $plan = ProofPlan::fromArray($fixture['plan']->toArray() + ['mutates' => true]);
        $promoted = $fixture['manifests']->promoted()?->toArray();
        $state = IssueState::forWorktree('NCK-123', $fixture['worktree']);

        expect(fn () => promoterFor($fixture['root'], $fixture['paths'], $fixture['manifests'])
            ->promote($fixture['request'], $plan))
            ->toThrow(RuntimeException::class, 'mutates: true');
        expect($events)
            ->toBe([])
            ->and($fixture['manifests']->promoted()?->toArray())
            ->toBe($promoted)
            ->and($state->hasAttempt())
            ->toBeTrue();
        Process::assertDidntRun(fn (PendingProcess $process): bool => ($process->command[0] ?? null) === 'incus');
    });

    it('refuses proof evidence recorded for a different plan without touching Incus', function (): void {
        $fixture = promotableFixture();
        $state = IssueState::forWorktree('NCK-123', $fixture['worktree']);
        $proof = $state->proof();
        assert($proof !== null);
        $proof['plan_sha256'] = str_repeat('f', 64);
        $state->writeProof($proof);
        $events = [];
        fakePromotionHost($fixture['target'], $events);

        expect(fn () => promoterFor($fixture['root'], $fixture['paths'], $fixture['manifests'])
            ->promote($fixture['request'], $fixture['plan']))
            ->toThrow(RuntimeException::class, 'proof plan does not match');
        expect($events)->toBe([]);
    });

    it('refuses incomplete or nonzero proof actions without touching Incus', function (array $actions): void {
        $fixture = promotableFixture();
        $state = IssueState::forWorktree('NCK-123', $fixture['worktree']);
        $proof = $state->proof();
        assert($proof !== null);
        $proof['actions'] = $actions;
        $state->writeProof($proof);
        $events = [];
        fakePromotionHost($fixture['target'], $events);

        expect(fn () => promoterFor($fixture['root'], $fixture['paths'], $fixture['manifests'])
            ->promote($fixture['request'], $fixture['plan']))
            ->toThrow(RuntimeException::class, 'complete zero-exit action evidence');
        expect($events)->toBe([]);
    })->with([
        'missing action' => [[]],
        'nonzero action' => [[['id' => 'doctor', 'node' => 'app-dev', 'exit_code' => 124]]],
    ]);

    it('refuses non-equivalent or wrong-head reports without touching Incus', function (string $case): void {
        $fixture = promotableFixture();
        mkdir($fixture['worktree'].'/docs', 0700, true);
        file_put_contents($fixture['worktree'].'/docs/correction.md', "correction\n");
        Process::run(['git', '-C', $fixture['worktree'], 'add', 'docs/correction.md'])->throw();
        Process::run(['git', '-C', $fixture['worktree'], 'commit', '--quiet', '-m', 'docs correction'])->throw();
        $accepted = new GitRepository($fixture['worktree'])->commit();
        Process::run(['git', '-C', $fixture['root'], 'branch', '-f', 'main', $accepted])->throw();
        $state = IssueState::forWorktree('NCK-123', $fixture['worktree']);
        $proof = $state->proof();
        assert($proof !== null && is_string($proof['manifest_sha256'] ?? null));
        $result = match ($case) {
            'stale' => ProofEquivalenceResult::Stale,
            'indeterminate' => ProofEquivalenceResult::Indeterminate,
            default => ProofEquivalenceResult::Equivalent,
        };
        $classification = match ($case) {
            'stale' => 'runtime',
            'indeterminate' => 'indeterminate',
            default => 'non-runtime',
        };
        $report = new ProofEquivalenceReport(
            $fixture['candidate'],
            $case === 'wrong-head' ? str_repeat('e', 40) : $accepted,
            $fixture['candidate'],
            $fixture['plan']->fingerprint(),
            $proof['manifest_sha256'],
            $result,
            [[
                'path' => 'docs/correction.md',
                'previous_path' => null,
                'change' => 'added',
                'classification' => $classification,
            ]],
            $result === ProofEquivalenceResult::Equivalent ? 'retained-proof' : null,
            match ($result) {
                ProofEquivalenceResult::Equivalent => 'review-exact-head',
                ProofEquivalenceResult::Stale => 'release-proof-and-run-complete-reproof',
                default => 'resolve-equivalence-failure-and-run-complete-reproof',
            },
            $result === ProofEquivalenceResult::Indeterminate ? ['Unknown input.'] : [],
            '2026-09-02T10:00:00Z',
        );
        $state->writeEquivalence($report->fingerprint(), $report->toArray());
        $events = [];
        fakePromotionHost($fixture['target'], $events);

        expect(fn () => promoterFor($fixture['root'], $fixture['paths'], $fixture['manifests'])
            ->promote($fixture['request'], $fixture['plan']))
            ->toThrow(RuntimeException::class, 'does not authorize retained-proof promotion')
            ->and($events)
            ->toBe([]);
    })->with(['stale', 'indeterminate', 'wrong-head']);

    it('refuses a candidate that main does not hold without touching Incus', function (): void {
        $fixture = promotableFixture(mainHoldsCandidate: false);
        $events = [];
        fakePromotionHost($fixture['target'], $events);

        expect(fn () => promoterFor($fixture['root'], $fixture['paths'], $fixture['manifests'])
            ->promote($fixture['request'], $fixture['plan']))
            ->toThrow(RuntimeException::class, 'does not hold the accepted candidate');
        Process::assertDidntRun(fn (PendingProcess $process): bool => ($process->command[0] ?? null) === 'incus');
    });
});
