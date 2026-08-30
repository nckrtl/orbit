<?php

declare(strict_types=1);

namespace App\E2E;

use App\E2E\Git\GitRepository;
use App\E2E\State\AtomicJsonStore;
use App\E2E\State\OperationJournal;
use App\E2E\State\OperationLock;
use App\E2E\State\SecretRedactor;
use App\E2E\State\StatePaths;
use App\E2E\Value\AttemptId;
use App\E2E\Value\AttemptPurpose;
use App\E2E\Value\CandidateSync;
use App\E2E\Value\FeatureTopology;
use App\E2E\Value\GuestCommand;
use App\E2E\Value\OperationId;
use App\E2E\Value\ProofPlan;
use App\E2E\Value\ProofResult;
use App\E2E\Value\ProofStatus;
use App\E2E\Value\SourceState;
use App\E2E\Value\StandbyGeneration;
use App\E2E\Value\TopologyProfile;
use App\E2E\Value\TopologyRequest;
use App\E2E\Value\TopologyTarget;
use App\E2E\Value\VerificationMode;
use App\E2E\Value\VerificationReport;
use Closure;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Prove one exact candidate commit on a fresh proof topology, as one operation.
 *
 * The proof needs a released and verified discovery attempt and no active
 * topology. It mints a new attempt, clones the promoted generation under names
 * disjoint from every discovery attempt, syncs exactly the candidate commit,
 * proves the clean guest identity, converges the repository behavior, runs the
 * declared setup and acceptance actions in order, and ends with the topology
 * proof verification.
 *
 * Failures while the resources are still being created (network, clone, start,
 * cloned host state) or while the candidate is being transferred roll the
 * partial attempt back against its intended inventory and retry once with a
 * fresh attempt. Once the complete owned topology holds the candidate, every
 * failure records a `diagnosis` and leaves the topology active for investigation.
 *
 * @mago-expect lint:excessive-parameter-list The proof dependencies are explicit trust boundaries.
 * @mago-expect lint:cyclomatic-complexity,kan-defect,too-many-methods The proof keeps its exact ordered operations together.
 */
final readonly class TopologyProofRunner
{
    /** Phases before the complete owned topology holds the candidate; each may retry once. */
    private const array RETRYABLE_PHASES = [
        'create.network',
        'clone',
        'start',
        'prepare.cloned-host-state',
        'sync.candidate',
    ];

    /** Phases during which the topology resources are not yet complete, so a failure rolls back. */
    private const array CREATION_PHASES = ['create.network', 'clone', 'start', 'prepare.cloned-host-state'];

    public function __construct(
        private IncusHost $host,
        private IncusNetworkLifecycle $networks,
        private StandbyManifestStore $standby,
        private TopologyManifestStore $manifests,
        private WorktreeSynchronizer $synchronizer,
        private TopologyConverger $converger,
        private TopologyVerifier $verifier,
        private ReleaseReceiptStore $receipts,
        private ProofStore $proofs,
        private HostCapacity $capacity,
        private AtomicJsonStore $state,
        private StatePaths $paths,
        private OperationId $operation,
        private OperationJournal $journal,
        private SecretRedactor $redactor,
        private string $repositoryRoot = '',
        private ?AcquisitionRollback $rollback = null,
        /** @var (Closure(): AttemptId)|null Mints the attempt identity; injectable so tests pin resource names. */
        private ?Closure $attempts = null,
    ) {}

    public function prove(TopologyRequest $request, string $candidateSha, ProofPlan $plan): ProofResult
    {
        if (preg_match('/\A[0-9a-f]{40}\z/D', $candidateSha) !== 1) {
            throw new InvalidArgumentException('The candidate must be an exact full SHA.');
        }
        $repository = new GitRepository($request->worktree);
        $this->assertRepositoryIdentity($request, $repository);
        try {
            $candidateTree = $repository->tree($candidateSha);
        } catch (InvalidArgumentException $exception) {
            throw new InvalidArgumentException(
                'The candidate commit is not reachable from the worktree repository.',
                previous: $exception,
            );
        }

        $lock = new OperationLock($this->paths);
        if (! $lock->acquire('topology-'.$request->issue, $this->operation)) {
            throw new RuntimeException('The issue topology is locked.');
        }
        try {
            return $this->proveLocked($request, $candidateSha, $candidateTree, $plan);
        } finally {
            $lock->release();
        }
    }

    /** Move one exact active proved attempt to `diagnosis`; the topology stays active for investigation. */
    public function diagnose(string $issue, AttemptId $attempt): ProofResult
    {
        TopologyTarget::assertIssue($issue);
        $lock = new OperationLock($this->paths);
        if (! $lock->acquire('topology-'.$issue, $this->operation)) {
            throw new RuntimeException('The issue topology is locked.');
        }
        try {
            $active = $this->manifests->active($issue);
            if ($active === null || $active->attempt->value !== $attempt->value) {
                throw new RuntimeException('The attempt is not the active topology attempt.');
            }
            if ($active->purpose !== AttemptPurpose::Proof) {
                throw new RuntimeException('The active topology attempt is not a proof attempt.');
            }
            $result = $this->proofs->diagnose($issue, $attempt);
            $this->journal->append($this->operation, [
                'event' => 'topology.diagnose',
                'state' => 'completed',
                'issue' => $issue,
                'attempt' => $attempt->value,
            ]);

            return $result;
        } finally {
            $lock->release();
        }
    }

    private function proveLocked(
        TopologyRequest $request,
        string $candidateSha,
        string $candidateTree,
        ProofPlan $plan,
    ): ProofResult {
        $issue = $request->issue;
        if ($this->receipts->latestDiscovery($issue) === null) {
            throw new RuntimeException('Proof requires a released and verified discovery attempt of the issue.');
        }
        if ($this->manifests->active($issue) !== null) {
            throw new RuntimeException('The issue already has an active topology attempt; release it before proof.');
        }
        if ($this->state->read('leases/'.$issue.'.json') !== null) {
            throw new RuntimeException('The issue still holds a topology lease; release it before proof.');
        }
        if ($this->state->read('standby/corrupt.json') !== null) {
            throw new RuntimeException('The promoted standby is marked corrupt.');
        }
        $generation = $this->standby->promoted() ?? throw new RuntimeException(
            'No promoted standby generation is available.',
        );
        $standbyTarget = TopologyTarget::standby();
        $this->host->assertOwnedSnapshots(array_combine(
            array_map($standbyTarget->instance(...), TopologyProfile::ROLES),
            $generation->snapshots,
        ));

        $retried = false;
        while (true) {
            $target = TopologyTarget::feature($issue, $this->mintAttempt());
            $timings = [];
            $phase = 'preflight';
            try {
                $sync = $this->createTopology(
                    $request,
                    $target,
                    $generation,
                    $candidateSha,
                    $candidateTree,
                    $timings,
                    $phase,
                );
            } catch (Throwable $exception) {
                $failedPhase = $phase;
                $this->journal->append($this->operation, [
                    'event' => 'topology.prove',
                    'state' => 'failed',
                    'issue' => $issue,
                    'attempt' => $target->requireAttempt()->value,
                    'phase' => $failedPhase,
                    'retry' => $retried,
                    'error' => $this->redactor->redact($exception->getMessage()),
                ]);
                $retry = ! $retried && in_array($failedPhase, self::RETRYABLE_PHASES, true);
                if ($retry || in_array($failedPhase, self::CREATION_PHASES, true)) {
                    $this->rollbackAttempt($target, $failedPhase, $timings);
                    if ($retry) {
                        $retried = true;
                        continue;
                    }
                    $verification = $this->failedVerification($target, $failedPhase, $exception);

                    return $this->record(
                        $target,
                        ProofStatus::Diagnosis,
                        $candidateSha,
                        $candidateTree,
                        null,
                        $plan,
                        [],
                        [],
                        $verification,
                        $timings,
                    );
                }

                // The complete owned topology exists: keep it for investigation.
                $verification = $this->failedVerification($target, $failedPhase, $exception);
                $this->activate($target, $generation, $candidateSha, $verification);

                return $this->record(
                    $target,
                    ProofStatus::Diagnosis,
                    $candidateSha,
                    $candidateTree,
                    null,
                    $plan,
                    [],
                    [],
                    $verification,
                    $timings,
                );
            }

            return $this->proveOnTopology($target, $generation, $sync, $plan, $timings);
        }
    }

    /**
     * Create the attempt resources and put the exact candidate on them. Returns
     * only when every checkout role proved the clean candidate identity.
     *
     * @param array<string, float> $timings
     * @mago-expect lint:excessive-parameter-list Every creation input stays explicit at the Incus boundary.
     */
    private function createTopology(
        TopologyRequest $request,
        TopologyTarget $target,
        StandbyGeneration $generation,
        string $candidateSha,
        string $candidateTree,
        array &$timings,
        string &$phase,
    ): CandidateSync {
        $issue = $request->issue;
        $attempt = $target->requireAttempt();
        $this->writeLease($issue, $attempt, 'acquiring');
        $slot = $this->capacity->reserve($issue, $attempt, $this->operation);
        $metadata = [
            'user.orbit.e2e.issue' => $issue,
            'user.orbit.e2e.attempt' => $attempt->value,
            'user.orbit.e2e.operation' => $this->operation->value,
        ];
        $phase = 'create.network';
        $this->measure($phase, $timings, fn () => $this->networks->create($target->network(), $slot, $metadata));

        $copies = [];
        foreach (TopologyProfile::ROLES as $role) {
            $copies[$role] = [
                'source' => TopologyTarget::standby()->instance($role),
                'snapshot' => $generation->snapshots[$role],
                'target' => $target->instance($role),
                'metadata' => [...$metadata, 'user.orbit.e2e.generation' => $generation->id],
                'network' => $target->network(),
                'role' => $role,
                'topology' => $target->network(),
                'slot' => $slot,
            ];
        }
        $phase = 'clone';
        $this->measure($phase, $timings, fn () => $this->copyPinnedSnapshots($generation, $copies));
        $instances = array_map($target->instance(...), TopologyProfile::ROLES);
        $phase = 'start';
        $this->measure($phase, $timings, fn () => $this->host->startAll($instances));
        $phase = 'prepare.cloned-host-state';
        $this->measure($phase, $timings, fn () => $this->host->prepareClonedHostStates($instances));
        $phase = 'sync.candidate';
        $sync = $this->measure(
            $phase,
            $timings,
            fn (): CandidateSync => $this->synchronizer->syncCommit($target, $request->worktree, $candidateSha),
        );
        if ($sync->candidateSha !== $candidateSha || $sync->candidateTree !== $candidateTree) {
            throw new RuntimeException('The candidate sync changed the candidate identity.');
        }
        $phase = 'identity';
        $this->measure(
            $phase,
            $timings,
            fn () => $this->synchronizer->probeCheckoutIdentity($target, $candidateSha, $candidateTree),
        );

        return $sync;
    }

    /**
     * Converge, run the plan, verify, and record; every failure here is a diagnosis
     * on a topology that stays active.
     *
     * @param array<string, float> $timings
     */
    private function proveOnTopology(
        TopologyTarget $target,
        StandbyGeneration $generation,
        CandidateSync $sync,
        ProofPlan $plan,
        array $timings,
    ): ProofResult {
        $source = $this->candidateSource($sync->candidateSha);
        $this->activate($target, $generation, $sync->candidateSha, $this->pendingVerification($target));
        $setup = [];
        $acceptance = [];
        $verification = null;
        $status = ProofStatus::Proved;
        $phase = 'converge';
        try {
            $this->measure($phase, $timings, fn () => $this->converger->converge(
                $target,
                $source,
                $generation->laravel,
            ));
            $phase = 'setup';
            $this->measure($phase, $timings, function () use ($target, $plan, &$setup): void {
                $this->runActions($target, 'setup', $plan->setup, $setup);
            });
            $phase = 'acceptance';
            $this->measure($phase, $timings, function () use ($target, $plan, &$acceptance): void {
                $this->runActions($target, 'acceptance', $plan->acceptance, $acceptance);
            });
            $phase = 'verify';
            $verification = $this->measure(
                $phase,
                $timings,
                fn (): VerificationReport => $this->verifier->verify($target, VerificationMode::Proof, $source),
            );
            if (! $verification->passed) {
                throw new RuntimeException('Candidate proof verification failed.'.$verification->failedSummary());
            }
        } catch (Throwable $exception) {
            $status = ProofStatus::Diagnosis;
            $verification ??= $this->failedVerification($target, $phase, $exception);
            $this->journal->append($this->operation, [
                'event' => 'topology.prove',
                'state' => 'failed',
                'issue' => $target->issue,
                'attempt' => $target->requireAttempt()->value,
                'phase' => $phase,
                'retry' => false,
                'error' => $this->redactor->redact($exception->getMessage()),
            ]);
        }

        $result = $this->record(
            $target,
            $status,
            $sync->candidateSha,
            $sync->candidateTree,
            $sync->guestScriptHash,
            $plan,
            $setup,
            $acceptance,
            $verification,
            $timings,
        );
        $this->activate($target, $generation, $sync->candidateSha, $verification);

        return $result;
    }

    /**
     * @param list<array{id:string,node:string,argv:list<string>,timeout_seconds:int}> $actions
     * @param list<array{id:string,node:string,argv:list<string>,exit_code:int,stdout:string,stderr:string,started_at:string,finished_at:string}> $results
     */
    private function runActions(TopologyTarget $target, string $section, array $actions, array &$results): void
    {
        foreach ($actions as $action) {
            $instance = $target->instance($action['node']);
            $this->journal->append($this->operation, [
                'event' => 'topology.prove.action',
                'state' => 'started',
                'section' => $section,
                'id' => $action['id'],
                'target' => $instance,
                'argv' => $this->redactor->redactArgv($action['argv']),
            ]);
            $startedAt = $this->timestamp();
            // The declared argv runs as the orbit runtime user; the record keeps it as given.
            $result = $this->host->exec(
                $instance,
                GuestCommand::asOrbitUser($action['argv'], $action['timeout_seconds']),
            );
            $finishedAt = $this->timestamp();
            $observed = [
                'id' => $action['id'],
                'node' => $action['node'],
                'argv' => $action['argv'],
                'exit_code' => $result->exitCode,
                'stdout' => $this->capture($result->stdout),
                'stderr' => $this->capture($result->stderr),
                'started_at' => $startedAt,
                'finished_at' => $finishedAt,
            ];
            $this->journal->append($this->operation, [
                'event' => 'topology.prove.action',
                'state' => 'completed',
                'section' => $section,
                'id' => $action['id'],
                'target' => $instance,
                'exit_code' => $observed['exit_code'],
                'stdout' => $observed['stdout'],
                'stderr' => $observed['stderr'],
            ]);
            $results[] = $observed;
            if (! $result->successful()) {
                throw new RuntimeException(
                    "Proof {$section} action [{$action['id']}] failed with exit code {$result->exitCode}.",
                );
            }
        }
    }

    /** Redacted, valid UTF-8, and capped: the record must hold no secret and always serialize. */
    private function capture(string $output): string
    {
        $value = mb_strcut($this->redactor->redact($output), 0, ProofResult::OUTPUT_LIMIT);
        if (mb_check_encoding($value, 'UTF-8')) {
            return $value;
        }
        $scrubbed = mb_convert_encoding($value, 'UTF-8', 'UTF-8');

        return is_string($scrubbed) ? $scrubbed : '';
    }

    /**
     * @param list<array{id:string,node:string,argv:list<string>,exit_code:int,stdout:string,stderr:string,started_at:string,finished_at:string}> $setup
     * @param list<array{id:string,node:string,argv:list<string>,exit_code:int,stdout:string,stderr:string,started_at:string,finished_at:string}> $acceptance
     * @param array<string, float> $timings
     * @mago-expect lint:excessive-parameter-list Every recorded proof field stays explicit.
     */
    private function record(
        TopologyTarget $target,
        ProofStatus $status,
        string $candidateSha,
        string $candidateTree,
        ?string $guestScriptHash,
        ProofPlan $plan,
        array $setup,
        array $acceptance,
        VerificationReport $verification,
        array $timings,
    ): ProofResult {
        $result = new ProofResult(
            $target->issue,
            $target->requireAttempt(),
            $status,
            $candidateSha,
            $candidateTree,
            $guestScriptHash,
            $this->candidateSource($candidateSha),
            $plan,
            $setup,
            $acceptance,
            $verification,
            ProofResult::now(),
            $this->operation->value,
        );
        $this->proofs->write($result);
        $this->journal->append($this->operation, [
            'event' => 'topology.prove.phases',
            'state' => $status->value,
            'issue' => $target->issue,
            'attempt' => $target->requireAttempt()->value,
            'duration_ms' => $timings,
        ]);

        return $result;
    }

    /** Write or refresh the active proof topology record with a ready lease. */
    private function activate(
        TopologyTarget $target,
        StandbyGeneration $generation,
        string $candidateSha,
        VerificationReport $verification,
    ): void {
        $instances = [];
        foreach (TopologyProfile::ROLES as $role) {
            $instances[$role] = $target->instance($role);
        }
        $this->manifests->writeActive(new FeatureTopology(
            $target,
            AttemptPurpose::Proof,
            $generation,
            $target->network(),
            $instances,
            $this->candidateSource($candidateSha),
            $verification,
        ));
        $this->writeLease($target->issue, $target->requireAttempt(), 'ready');
    }

    private function candidateSource(string $candidateSha): SourceState
    {
        return new SourceState($candidateSha, $candidateSha, operationId: $this->operation->value);
    }

    /**
     * Roll back every intended resource of one attempt and prove absence; a
     * refused or incomplete cleanup fails closed with the attempt state retained.
     *
     * @param array<string, float> $timings
     */
    private function rollbackAttempt(TopologyTarget $target, string $phase, array $timings): void
    {
        $issue = $target->issue;
        $attempt = $target->requireAttempt();
        $resources = [$target->network(), ...array_map($target->instance(...), TopologyProfile::ROLES)];
        $rollback = $this->rollback ?? AcquisitionRollback::forHost($this->host, $this->networks, $target);
        $observed = $rollback->observe($resources);
        $cleanup = $rollback->cleanup($target, $resources, $observed, $this->operation);
        $this->journal->append($this->operation, [
            'event' => 'topology.prove.rollback',
            'state' => 'completed',
            'issue' => $issue,
            'attempt' => $attempt->value,
            'phase' => $phase,
            'duration_ms' => $timings,
            'cleanup' => array_map($this->redactor->redact(...), $cleanup),
        ]);
        $refused = [];
        /** @mago-expect analysis:mixed-assignment Each cleanup verdict is compared against the exact accepted values. */
        foreach ($cleanup as $resource => $result) {
            if (! in_array($result, ['absent', 'removed'], true)) {
                $refused[] = "{$resource}={$result}";
            }
        }
        if ($refused !== []) {
            throw new RuntimeException(
                'Proof topology rollback was refused; the attempt lease is retained: '.implode('; ', $refused),
            );
        }
        $this->capacity->release($issue, $attempt, $this->operation);
        $this->state->delete('leases/'.$issue.'.json');
    }

    /** The proof record of an attempt that never reached verification names the failed phase. */
    private function failedVerification(TopologyTarget $target, string $phase, Throwable $exception): VerificationReport
    {
        $observed = trim($this->redactor->redact(mb_strcut($exception->getMessage(), 0, 1_024)));

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

    /** The record of a proof still running names no verdict yet. */
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
     * Hold the shared generation pin only across the exact snapshot copy, as
     * discovery does, so refresh cannot replace the source between proof and copy.
     *
     * @param array<string, array{source:string,snapshot:string,target:string,metadata:array<string, string>,network?:string,role?:string,topology?:string,slot?:int}> $copies
     */
    private function copyPinnedSnapshots(StandbyGeneration $generation, array $copies): void
    {
        $lock = new OperationLock($this->paths);
        if (! $lock->acquire('standby-generation', $this->operation, exclusive: false, timeoutSeconds: 3600)) {
            throw new RuntimeException('The promoted standby generation is locked.');
        }
        try {
            if ($this->standby->promoted()?->toArray() !== $generation->toArray()) {
                throw new RuntimeException('The promoted standby generation changed before snapshot copy.');
            }
            $this->host->copySnapshots($copies);
        } finally {
            $lock->release();
        }
    }

    /** The worktree must belong to this repository and to the issue branch; the candidate comes from it. */
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
        if ($this->attempts === null) {
            return AttemptId::generate();
        }

        return ($this->attempts)();
    }

    private function writeLease(string $issue, AttemptId $attempt, string $state): void
    {
        $lease = [
            'schema' => 2,
            'issue' => $issue,
            'attempt' => $attempt->value,
            'state' => $state,
            'operation_id' => $this->operation->value,
            'expires_at' => gmdate('Y-m-d\TH:i:s\Z', time() + 604_800),
        ];
        if ($state === 'acquiring') {
            $owner = OperationLock::currentOwner($this->operation);
            $lease += [
                'pid' => $owner['pid'],
                'process_start_identity' => $owner['process_start_identity'],
                'acquired_at' => $owner['acquired_at'],
            ];
        }
        $this->state->write('leases/'.$issue.'.json', $lease);
    }

    private function timestamp(): string
    {
        return new \DateTimeImmutable('now', new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z');
    }

    /**
     * @template T
     * @param array<string, float> $timings
     * @param callable(): T $operation
     * @return T
     */
    private function measure(string $phase, array &$timings, callable $operation): mixed
    {
        $started = hrtime(true);
        try {
            return $operation();
        } finally {
            $timings[$phase] = round((hrtime(true) - $started) / 1_000_000, 3);
        }
    }
}
