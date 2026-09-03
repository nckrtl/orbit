<?php

declare(strict_types=1);

namespace App\E2E;

use App\E2E\Git\GitRepository;
use App\E2E\State\OperationLock;
use App\E2E\State\StatePaths;
use App\E2E\Value\AttemptId;
use App\E2E\Value\AttemptPurpose;
use App\E2E\Value\CandidateConvergenceResult;
use App\E2E\Value\FeatureTopology;
use App\E2E\Value\GuestCommand;
use App\E2E\Value\ObservedPhpInputs;
use App\E2E\Value\OperationId;
use App\E2E\Value\ProofEquivalenceReport;
use App\E2E\Value\ProofEquivalenceResult;
use App\E2E\Value\ProofInputManifest;
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
 * The worktree must be clean and hold no proof attempt. Its discovery topology
 * may remain active. The runner mints a separate attempt, clones the promoted
 * generation, transfers exactly the candidate commit, stages fixtures,
 * converges, runs every action in order, and verifies. A failure before the
 * VMs hold the candidate rolls the proof back; every later failure records a
 * `diagnosis` and keeps the proof topology alive for explicit inspection.
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
        private ProofInputManifestBuilder $proofInputs,
        private ObservedPhpInputCollector $observedPhpInputs,
        private string $repositoryRoot = '',
        /** @var (Closure(): AttemptId)|null Mints the attempt identity; injectable so tests pin resource names. */
        private ?Closure $attempts = null,
    ) {}

    /**
     * Converge and generally verify the exact accepted candidate without rerunning feature actions.
     *
     * @return array<string, mixed>
     */
    public function convergeCandidate(TopologyRequest $request): array
    {
        $repository = new GitRepository($request->worktree);
        $this->assertRepositoryIdentity($request, $repository);
        if ($repository->dirtyOverlay() !== null) {
            throw new InvalidArgumentException('The worktree must be clean before candidate convergence.');
        }
        $candidateSha = $repository->commit();
        $candidateTree = $repository->tree($candidateSha);
        $state = IssueState::forWorktree($request->issue, $request->worktree);
        if (! $state->isProved()) {
            throw new RuntimeException("{$request->issue} has no retained proof for candidate convergence.");
        }
        $rawReport = $state->equivalence() ?? throw new RuntimeException(
            'Candidate convergence requires an equivalence report.',
        );
        $report = ProofEquivalenceReport::fromArray($rawReport);
        $proof = $state->proof() ?? [];
        $manifestFingerprint = $proof['manifest_sha256'] ?? null;
        $rawManifest = is_string($manifestFingerprint)
            ? $state->proofInputManifest($manifestFingerprint)
            : null;
        try {
            $manifest = is_array($rawManifest) ? ProofInputManifest::fromArray($rawManifest) : null;
        } catch (Throwable $exception) {
            throw new RuntimeException(
                'Candidate convergence requires a valid observed-input manifest.',
                0,
                $exception,
            );
        }
        if (
            $report->acceptedSha !== $candidateSha
            || $report->result !== ProofEquivalenceResult::Equivalent
            || $report->promotionPath !== 'candidate-convergence'
            || $report->errors !== []
            || ($proof['candidate_sha'] ?? null) !== $report->provedSha
            || ($proof['plan_sha256'] ?? null) !== $report->planSha256
            || $manifest === null
            || $manifest->fingerprint() !== $report->manifestSha256
            || $manifest->observedInputs === null
            || $manifest->policyVersion !== StaticProofInputPolicy::VERSION
            || in_array(false, $manifest->completeness, true)
        ) {
            throw new RuntimeException('The equivalence report does not authorize candidate convergence.');
        }

        $lock = new OperationLock($this->hostPaths);
        if (! $lock->acquire('topology-'.$request->issue, $this->operation)) {
            throw new RuntimeException('The issue topology is locked by another harness command.');
        }
        try {
            if ($state->hasAttempt(AttemptPurpose::CandidateConvergence)) {
                throw new RuntimeException('A candidate-convergence attempt already exists; release it first.');
            }

            return $this->convergeCandidateLocked(
                $request,
                $state,
                $candidateSha,
                $candidateTree,
                $report,
            );
        } finally {
            $lock->release();
        }
    }

    public function prove(TopologyRequest $request, ProofPlan $plan, string $planPath): ProofResult
    {
        $repository = new GitRepository($request->worktree);
        $this->assertRepositoryIdentity($request, $repository);
        if ($repository->dirtyOverlay() !== null) {
            throw new InvalidArgumentException('The worktree must be clean: commit or stash before proof.');
        }
        $generation = $this->topologySnapshot->promoted() ?? throw new RuntimeException(
            'No promoted topology snapshot generation is available.',
        );
        if ($generation->isLegacy()) {
            throw new RuntimeException('The promoted topology snapshot generation is legacy; refresh it before proof.');
        }
        $candidateSha = $repository->commit();
        $candidateTree = $repository->tree($candidateSha);
        $includedMainSha = $repository->commit('origin/main');
        $this->proofInputs->validateContract(
            $repository,
            $candidateSha,
            $includedMainSha,
            $request->issue,
            $planPath,
            $plan,
        );

        $state = IssueState::forWorktree($request->issue, $request->worktree);
        $lock = new OperationLock($this->hostPaths);
        if (! $lock->acquire('topology-'.$request->issue, $this->operation)) {
            throw new RuntimeException('The issue topology is locked by another harness command.');
        }
        try {
            if ($state->hasAttempt(AttemptPurpose::Proof)) {
                throw new RuntimeException(
                    "{$request->issue} already has proof attempt "
                    .$state->attemptId(AttemptPurpose::Proof)->value
                    .'; release it before another proof.',
                );
            }

            return $this->proveLocked(
                $request,
                $state,
                $repository,
                $candidateSha,
                $candidateTree,
                $includedMainSha,
                $plan,
                $planPath,
            );
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
        string $includedMainSha,
        ProofPlan $plan,
        string $planPath,
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
            $this->rollback($target, $state, AttemptPurpose::Proof, $exception);
        }

        $source = $this->candidateSource($candidateSha);
        $this->record($state, $target, $generation, $source, $this->pendingVerification($target));
        $actions = [];
        $error = null;
        $verification = null;
        $manifest = null;
        $instrumented = false;
        $phase = 'sync.candidate';
        try {
            $sync = $this->synchronizer->syncCommit($target, $request->worktree, $candidateSha);
            if ($sync->candidateSha !== $candidateSha || $sync->candidateTree !== $candidateTree) {
                throw new RuntimeException('The candidate sync changed the candidate identity.');
            }
            $phase = 'identity';
            $this->synchronizer->probeCheckoutIdentity($target, $candidateSha, $candidateTree);
            $phase = 'fixtures';
            $this->fixtures->stage($target, $repository, $candidateSha);
            $phase = 'sury-runtime';
            $this->observedPhpInputs->normalizeRuntime($target);
            $phase = 'converge';
            $this->converger->converge($target, $source, $generation->laravel);
            $entries = $repository->entries($candidateSha);
            $observed = null;
            if ($plan->observedInputs) {
                $phase = 'pcov.prepare';
                $instrumented = true;
                $runtimes = $this->observedPhpInputs->prepare($target);
                $observedPhases = [];
                foreach (ObservedPhpInputs::PHASES as $observedPhase) {
                    $phase = "pcov.{$observedPhase}.begin";
                    $this->observedPhpInputs->begin(
                        $target,
                        $observedPhase,
                        $request->issue,
                        $target->requireAttempt(),
                    );
                    $phase = $observedPhase;
                    $declared = $observedPhase === 'setup' ? $plan->setup : $plan->acceptance;
                    $this->runActions($target, $observedPhase, $declared, $actions);
                    $phase = "pcov.{$observedPhase}.collect";
                    $observedPhases[$observedPhase] = $this->observedPhpInputs->collect(
                        $target,
                        $observedPhase,
                        $request->issue,
                        $target->requireAttempt(),
                        $runtimes,
                        $entries,
                    );
                }
                $phase = 'pcov.cleanup';
                $this->observedPhpInputs->cleanup($target);
                $instrumented = false;
                $observed = new ObservedPhpInputs($runtimes, $observedPhases);
            } else {
                $phase = 'setup';
                $this->runActions($target, 'setup', $plan->setup, $actions);
                $phase = 'acceptance';
                $this->runActions($target, 'acceptance', $plan->acceptance, $actions);
            }
            $phase = 'manifest';
            $manifest = $this->proofInputs->build(
                $repository,
                $candidateSha,
                $includedMainSha,
                $request->issue,
                $planPath,
                $plan,
                $observed,
            );
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
            if ($instrumented) {
                try {
                    $this->observedPhpInputs->cleanup($target);
                } catch (Throwable $cleanup) {
                    $exception = new RuntimeException(
                        $exception->getMessage().'; PCOV cleanup also failed: '.$cleanup->getMessage(),
                        previous: $exception,
                    );
                }
            }
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
            $plan->fingerprint(),
            $status === ProofStatus::Proved && $manifest instanceof ProofInputManifest
                ? $manifest->fingerprint()
                : null,
        );
        $this->record($state, $target, $generation, $source, $verification);
        if ($status === ProofStatus::Proved) {
            if (! $manifest instanceof ProofInputManifest) {
                throw new RuntimeException('The successful proof has no proof-input manifest.');
            }
            $repository->pinProof($request->issue, $target->requireAttempt(), $candidateSha);
            try {
                $state->writeProofInputManifest($manifest->fingerprint(), $manifest->toArray());
                $state->writeProof($result->toArray());
            } catch (Throwable $exception) {
                $repository->unpinProof($request->issue, $target->requireAttempt());

                throw $exception;
            }

            return $result;
        }
        $state->writeProof($result->toArray());

        return $result;
    }

    /** @return array<string, mixed> */
    private function convergeCandidateLocked(
        TopologyRequest $request,
        IssueState $state,
        string $candidateSha,
        string $candidateTree,
        ProofEquivalenceReport $equivalence,
    ): array {
        $generation = $this->topologySnapshot->promoted() ?? throw new RuntimeException(
            'No promoted topology snapshot generation is available.',
        );
        if ($generation->isLegacy()) {
            throw new RuntimeException('The promoted topology snapshot generation is legacy; refresh it first.');
        }
        $target = TopologyTarget::feature($request->issue, $this->mintAttempt());
        $purpose = AttemptPurpose::CandidateConvergence;
        $state->writeAttempt($target->requireAttempt(), $purpose, $this->operation);
        try {
            $this->createTopology($target, $generation);
        } catch (Throwable $exception) {
            $this->rollback($target, $state, $purpose, $exception);
        }

        $source = $this->candidateSource($candidateSha);
        $verification = $this->pendingVerification($target);
        $this->record($state, $target, $generation, $source, $verification, $purpose);
        $phase = 'sync.candidate';
        $convergence = null;
        $error = null;
        try {
            $sync = $this->synchronizer->syncCommit($target, $request->worktree, $candidateSha);
            if ($sync->candidateSha !== $candidateSha || $sync->candidateTree !== $candidateTree) {
                throw new RuntimeException('The candidate sync changed the candidate identity.');
            }
            $phase = 'identity';
            $this->synchronizer->probeCheckoutIdentity($target, $candidateSha, $candidateTree);
            $phase = 'sury-runtime';
            $this->observedPhpInputs->normalizeRuntime($target);
            $phase = 'converge';
            $convergence = $this->converger->converge($target, $source, $generation->laravel);
            $phase = 'verify';
            $verification = $this->verifier->verify(
                $target,
                VerificationMode::Proof,
                $source,
                requiredAssignments: $generation->topologyAssignments ?? throw new RuntimeException(
                    'The pinned generation has no assignment declaration.',
                ),
            );
            if (! $verification->passed) {
                throw new RuntimeException('Candidate convergence verification failed.'.$verification->failedSummary());
            }
            $status = 'converged';
        } catch (Throwable $exception) {
            $status = 'diagnosis';
            $error = "candidate-convergence phase {$phase} failed: ".$exception->getMessage();
            $verification = $this->failedVerification($target, $phase, $exception);
        }
        $this->record($state, $target, $generation, $source, $verification, $purpose);
        $result = new CandidateConvergenceResult(
            $status,
            $request->issue,
            $target->requireAttempt(),
            $candidateSha,
            $candidateTree,
            $equivalence->fingerprint(),
            $convergence,
            $verification,
            $error,
            ProofResult::now(),
        );
        $state->writeCandidateConvergence($result);

        return $result->toArray();
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
     * @param list<array{id:string,node:string,argv:list<string>,timeout_seconds:int}> $declared
     * @param list<array{id:string,node:string,exit_code:int,stdout:string,stderr:string}> $actions
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
                'exit_code' => $result->exitCode,
                'stdout' => ProofResult::tail($result->stdout),
                'stderr' => ProofResult::tail($result->stderr),
            ];
            if (! $result->successful()) {
                throw new RuntimeException(
                    "Proof {$section} action [{$action['id']}] failed with exit code {$result->exitCode}.",
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
        AttemptPurpose $purpose = AttemptPurpose::Proof,
    ): void {
        $instances = [];
        foreach (TopologyProfile::ROLES as $role) {
            $instances[$role] = $target->instance($role);
        }
        $state->writeTopology(new FeatureTopology(
            $target,
            $purpose,
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
    private function rollback(
        TopologyTarget $target,
        IssueState $state,
        AttemptPurpose $purpose,
        Throwable $exception,
    ): never {
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
        $state->forgetAttempt($purpose);

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
