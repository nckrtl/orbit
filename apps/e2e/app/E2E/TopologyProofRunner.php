<?php

declare(strict_types=1);

namespace App\E2E;

use App\E2E\Git\GitRepository;
use App\E2E\State\OperationLock;
use App\E2E\State\StatePaths;
use App\E2E\Value\AttemptId;
use App\E2E\Value\AttemptPurpose;
use App\E2E\Value\FeatureTopology;
use App\E2E\Value\GuestCommand;
use App\E2E\Value\OperationId;
use App\E2E\Value\ProofPlan;
use App\E2E\Value\ProofResult;
use App\E2E\Value\ProofStatus;
use App\E2E\Value\SourceState;
use App\E2E\Value\TopologyProfile;
use App\E2E\Value\TopologyRequest;
use App\E2E\Value\TopologySnapshotGeneration;
use App\E2E\Value\TopologySnapshotIdentity;
use App\E2E\Value\TopologyTarget;
use App\E2E\Value\VerificationMode;
use App\E2E\Value\VerificationReport;
use Closure;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Prove the worktree's HEAD commit on a fresh topology.
 *
 * The worktree must be clean and hold no live attempt. The runner mints an
 * attempt, clones the promoted generation, transfers exactly the candidate
 * commit, stages `proofs/<ISSUE>/` fixtures, converges, runs the plan's setup
 * and acceptance actions in order, and verifies. A failure before the VMs
 * hold the candidate rolls the attempt back; every later failure records a
 * `diagnosis` and keeps the topology alive until `release`.
 *
 * @mago-expect lint:excessive-parameter-list The proof dependencies are explicit trust boundaries.
 * @mago-expect lint:cyclomatic-complexity,kan-defect,too-many-methods The proof keeps its exact ordered operations together.
 */
final readonly class TopologyProofRunner
{
    public function __construct(
        private IncusHost $host,
        private IncusNetworkLifecycle $networks,
        private TopologySnapshotManifestStore $topologySnapshot,
        private WorktreeSynchronizer $synchronizer,
        private TopologyConverger $converger,
        private TopologyVerifier $verifier,
        private ProofFixtureStager $fixtures,
        private HostCapacity $capacity,
        private StatePaths $hostPaths,
        private OperationId $operation,
        private TopologySnapshotIdentity $topologySnapshotIdentity,
        private string $repositoryRoot = '',
        /** @var (Closure(): AttemptId)|null Mints the attempt identity; injectable so tests pin resource names. */
        private ?Closure $attempts = null,
    ) {}

    public function prove(TopologyRequest $request, ProofPlan $plan): ProofResult
    {
        $repository = new GitRepository($request->worktree);
        $this->assertRepositoryIdentity($request, $repository);
        if ($repository->dirtyOverlay() !== null) {
            throw new InvalidArgumentException('The worktree must be clean: commit or stash before proof.');
        }
        $candidateSha = $repository->commit();
        $candidateTree = $repository->tree($candidateSha);

        $state = IssueState::forWorktree($request->issue, $request->worktree);
        $lock = new OperationLock($this->hostPaths);
        if (! $lock->acquire('topology-'.$request->issue, $this->operation)) {
            throw new RuntimeException('The issue topology is locked by another harness command.');
        }
        try {
            if ($state->hasAttempt()) {
                throw new RuntimeException(
                    "{$request->issue} already has attempt {$state->attemptId()->value}; release it before proof.",
                );
            }

            return $this->proveLocked($request, $state, $repository, $candidateSha, $candidateTree, $plan);
        } finally {
            $lock->release();
        }
    }

    private function proveLocked(
        TopologyRequest $request,
        IssueState $state,
        GitRepository $repository,
        string $candidateSha,
        string $candidateTree,
        ProofPlan $plan,
    ): ProofResult {
        $generation = $this->topologySnapshot->promoted() ?? throw new RuntimeException(
            'No promoted topology snapshot generation is available.',
        );
        if ($generation->isLegacy()) {
            throw new RuntimeException('The promoted topology snapshot generation is legacy; refresh it before proof.');
        }
        $topologySnapshotTarget = TopologyTarget::topologySnapshot($this->topologySnapshotIdentity);
        $this->host->assertOwnedSnapshots(array_combine(
            array_map($topologySnapshotTarget->instance(...), TopologyProfile::ROLES),
            $generation->snapshots,
        ));

        $target = TopologyTarget::feature($request->issue, $this->mintAttempt());
        $state->writeAttempt($target->requireAttempt(), AttemptPurpose::Proof, $this->operation);
        try {
            $this->createTopology($target, $generation);
        } catch (Throwable $exception) {
            $this->rollback($target, $state, $exception);
        }

        $source = $this->candidateSource($candidateSha);
        $this->record($state, $target, $generation, $source, $this->pendingVerification($target));
        $actions = [];
        $error = null;
        $verification = null;
        $phase = 'sync.candidate';
        try {
            $sync = $this->synchronizer->syncCommit($target, $request->worktree, $candidateSha);
            if ($sync->candidateSha !== $candidateSha || $sync->candidateTree !== $candidateTree) {
                throw new RuntimeException('The candidate sync changed the candidate identity.');
            }
            $phase = 'identity';
            $this->synchronizer->probeCheckoutIdentity($target, $candidateSha, $candidateTree);
            $phase = 'fixtures';
            $this->fixtures->stage($target, $repository, $candidateSha, $plan->fixtureIssues);
            $phase = 'converge';
            $this->converger->converge($target, $source, $generation->laravel);
            $phase = 'setup';
            $this->runActions($target, 'setup', $plan->setup, $actions);
            $phase = 'acceptance';
            $this->runActions($target, 'acceptance', $plan->acceptance, $actions);
            $phase = 'verify';
            $verification = $this->verifier->verify(
                $target,
                VerificationMode::Proof,
                $source,
                $plan->endsWith,
                $generation->topologyAssignments ?? throw new RuntimeException(
                    'The pinned generation has no assignment declaration.',
                ),
            );
            if (! $verification->passed) {
                throw new RuntimeException('Candidate proof verification failed.'.$verification->failedSummary());
            }
            $status = ProofStatus::Proved;
        } catch (Throwable $exception) {
            $status = ProofStatus::Diagnosis;
            $error = "proof phase {$phase} failed: ".$exception->getMessage();
            $verification ??= $this->failedVerification($target, $phase, $exception);
        }

        $result = new ProofResult(
            $request->issue,
            $target->requireAttempt(),
            $status,
            $candidateSha,
            $actions,
            $error,
            ProofResult::now(),
            $plan->endsWith,
            TopologyVerifier::skippedProbes($plan->endsWith),
        );
        $this->record($state, $target, $generation, $source, $verification);
        $state->writeProof($result->toArray());

        return $result;
    }

    /** Network and clones, under the host creation lock; nothing of the candidate is on them yet. */
    private function createTopology(TopologyTarget $target, TopologySnapshotGeneration $generation): void
    {
        $metadata = [
            'user.orbit.e2e.issue' => $target->issue,
            'user.orbit.e2e.attempt' => $target->requireAttempt()->value,
            'user.orbit.e2e.operation' => $this->operation->value,
        ];
        $creation = new OperationLock($this->hostPaths);
        if (! $creation->acquire(OrphanNetworkSweep::CREATION_LOCK, $this->operation, timeoutSeconds: 600)) {
            throw new RuntimeException('Another topology creation holds the host.');
        }
        try {
            $slot = $this->capacity->reserveSlot();
            $this->networks->create($target->network(), $slot, $metadata);
            $copies = [];
            foreach (TopologyProfile::ROLES as $role) {
                $copies[$role] = [
                    'source' => TopologyTarget::topologySnapshot($this->topologySnapshotIdentity)->instance($role),
                    'snapshot' => $generation->snapshots[$role],
                    'target' => $target->instance($role),
                    'metadata' => [...$metadata, 'user.orbit.e2e.generation' => $generation->id],
                    'network' => $target->network(),
                    'role' => $role,
                    'topology' => $target->network(),
                    'slot' => $slot,
                ];
            }
            $this->copyPinnedSnapshots($generation, $copies);
        } finally {
            $creation->release();
        }
        $instances = array_map($target->instance(...), TopologyProfile::ROLES);
        $this->host->startAll($instances);
        $this->host->prepareClonedHostStates($instances);
    }

    /**
     * @param list<array{id:string,node:string,argv:list<string>,timeout_seconds:int,expected_exit_code:int}> $declared
     * @param list<array{id:string,node:string,expected_exit_code:int,exit_code:int,stdout:string,stderr:string}> $actions
     */
    private function runActions(TopologyTarget $target, string $section, array $declared, array &$actions): void
    {
        foreach ($declared as $action) {
            $instance = $target->instance($action['node']);
            try {
                $result = $this->host->exec(
                    $instance,
                    GuestCommand::asProofAction($action['argv'], $action['timeout_seconds']),
                );
            } catch (Throwable $transport) {
                $actions[] = [
                    'id' => $action['id'],
                    'node' => $action['node'],
                    'expected_exit_code' => $action['expected_exit_code'],
                    'exit_code' => -1,
                    'stdout' => '',
                    'stderr' => $transport->getMessage(),
                ];
                throw new RuntimeException(
                    "Proof {$section} action [{$action['id']}] could not run: ".$transport->getMessage(),
                    previous: $transport,
                );
            }
            $actions[] = [
                'id' => $action['id'],
                'node' => $action['node'],
                'expected_exit_code' => $action['expected_exit_code'],
                'exit_code' => $result->exitCode,
                'stdout' => ProofResult::tail($result->stdout),
                'stderr' => ProofResult::tail($result->stderr),
            ];
            if ($result->exitCode !== $action['expected_exit_code']) {
                throw new RuntimeException(
                    "Proof {$section} action [{$action['id']}] expected exit code "
                    ."{$action['expected_exit_code']}, got {$result->exitCode}.",
                );
            }
        }
    }

    private function record(
        IssueState $state,
        TopologyTarget $target,
        TopologySnapshotGeneration $generation,
        SourceState $source,
        VerificationReport $verification,
    ): void {
        $instances = [];
        foreach (TopologyProfile::ROLES as $role) {
            $instances[$role] = $target->instance($role);
        }
        $state->writeTopology(new FeatureTopology(
            $target,
            AttemptPurpose::Proof,
            $generation,
            $target->network(),
            $instances,
            $source,
            $verification,
        ));
    }

    private function candidateSource(string $candidateSha): SourceState
    {
        return new SourceState($candidateSha, $candidateSha, operationId: $this->operation->value);
    }

    /** Roll every intended resource back and drop the lease; a refused cleanup keeps the lease for `release`. */
    private function rollback(TopologyTarget $target, IssueState $state, Throwable $exception): never
    {
        $resources = [$target->network(), ...array_map($target->instance(...), TopologyProfile::ROLES)];
        $rollback = AcquisitionRollback::forHost($this->host, $this->networks, $target);
        $refused = [];
        try {
            $cleanup = $rollback->cleanup($target, $resources, $rollback->observe($resources), $this->operation);
            foreach ($cleanup as $resource => $result) {
                if (! in_array($result, ['absent', 'removed'], true)) {
                    $refused[] = "{$resource}={$result}";
                }
            }
        } catch (Throwable $cleanupFailure) {
            $refused[] = 'cleanup: '.$cleanupFailure->getMessage();
        }
        if ($refused !== []) {
            throw new RuntimeException(
                'Proof topology creation failed: '.$exception->getMessage().'; rollback was refused: '
                    .implode('; ', $refused),
                previous: $exception,
            );
        }
        $state->forgetAttempt();

        throw new RuntimeException('Proof topology creation failed: '.$exception->getMessage(), previous: $exception);
    }

    private function failedVerification(TopologyTarget $target, string $phase, Throwable $exception): VerificationReport
    {
        $observed = trim(mb_strcut($exception->getMessage(), 0, 1_024));

        return new VerificationReport(false, [
            'proof.'.$phase => [
                'passed' => false,
                'checked_at' => gmdate('Y-m-d\\TH:i:s\\Z'),
                'expected' => "proof phase {$phase} completed",
                'observed' => $observed === '' ? 'failed' : $observed,
                'evidence_ref' => 'incus://'.$target->instance('gateway').'/proof.'.$phase,
            ],
        ]);
    }

    private function pendingVerification(TopologyTarget $target): VerificationReport
    {
        return new VerificationReport(false, [
            'proof.pending' => [
                'passed' => false,
                'checked_at' => gmdate('Y-m-d\\TH:i:s\\Z'),
                'expected' => 'proof verification completed',
                'observed' => 'proof in progress',
                'evidence_ref' => 'incus://'.$target->instance('gateway').'/proof.pending',
            ],
        ]);
    }

    /**
     * @param array<string, array{source:string,snapshot:string,target:string,metadata:array<string, string>,network?:string,role?:string,topology?:string,slot?:int}> $copies
     */
    private function copyPinnedSnapshots(TopologySnapshotGeneration $generation, array $copies): void
    {
        $lock = new OperationLock($this->hostPaths);
        if (! $lock->acquire(
            'standby-generation',
            $this->operation,
            exclusive: false,
            timeoutSeconds: 3600,
        )) {
            throw new RuntimeException('The promoted topology snapshot generation is locked.');
        }
        try {
            if ($this->topologySnapshot->promoted()?->toArray() !== $generation->toArray()) {
                throw new RuntimeException('The promoted topology snapshot generation changed before snapshot copy.');
            }
            $this->host->copySnapshots($copies);
        } finally {
            $lock->release();
        }
    }

    private function assertRepositoryIdentity(TopologyRequest $request, GitRepository $repository): void
    {
        $expectedRoot = $this->repositoryRoot !== '' ? $this->repositoryRoot : dirname(__DIR__, 4);
        if ($repository->commonDirectory() !== new GitRepository($expectedRoot)->commonDirectory()) {
            throw new InvalidArgumentException('The worktree repository identity does not match Orbit.');
        }
        if (! TopologyTarget::issueMatchesBranch($request->issue, $repository->branch())) {
            throw new InvalidArgumentException('The worktree branch does not match the issue.');
        }
    }

    private function mintAttempt(): AttemptId
    {
        return $this->attempts === null ? AttemptId::generate() : ($this->attempts)();
    }
}
