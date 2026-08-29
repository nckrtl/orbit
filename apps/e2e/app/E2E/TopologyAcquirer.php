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
use App\E2E\Value\FeatureTopology;
use App\E2E\Value\GuestCommand;
use App\E2E\Value\GuestCommandResult;
use App\E2E\Value\IncusInstance;
use App\E2E\Value\IncusNetwork;
use App\E2E\Value\MountPath;
use App\E2E\Value\OperationId;
use App\E2E\Value\ProofResult;
use App\E2E\Value\SourceState;
use App\E2E\Value\TopologyProfile;
use App\E2E\Value\TopologyRequest;
use App\E2E\Value\TopologyTarget;
use App\E2E\Value\VerificationMode;
use Closure;
use Illuminate\Support\Facades\Process;
use RuntimeException;
use Throwable;

/**
 * @mago-expect lint:excessive-parameter-list The lifecycle dependencies are explicit trust boundaries.
 * @mago-expect lint:cyclomatic-complexity,kan-defect,too-many-methods The lifecycle keeps its exact ordered operations together.
 */
final readonly class TopologyAcquirer
{
    /** Host `bin/bootstrap` owns vendor; guests never run composer in discovery. */
    private const array REQUIRED_VENDOR_AUTOLOADS = [
        'apps/gateway/vendor/autoload.php',
        'apps/cli/vendor/autoload.php',
        'packages/php-sdk/vendor/autoload.php',
    ];

    public function __construct(
        private IncusHost $host,
        private IncusNetworkLifecycle $networks,
        private PreparedStateFingerprint $fingerprints,
        private StandbyManifestStore $standby,
        private TopologyManifestStore $manifests,
        private WorktreeSynchronizer $synchronizer,
        private TopologyConverger $converger,
        private TopologyVerifier $verifier,
        private AtomicJsonStore $state,
        private StatePaths $paths,
        private OperationId $commandOperation,
        private OperationJournal $journal,
        private SecretRedactor $redactor,
        private HostCapacity $capacity,
        private ProofRecordReader $proofs,
        private DiscoveryGuestPreparer $guests,
        private string $repositoryRoot = '',
        private ?AcquisitionRollback $rollback = null,
        /** @var (Closure(): AttemptId)|null Mints the attempt identity; injectable so tests pin resource names. */
        private ?Closure $attempts = null,
    ) {}

    /**
     * Acquire one disposable discovery attempt: clone the promoted generation,
     * mount the worktree on the checkout roles, repair the clone identity, record
     * the host source identity, and verify readiness. No source is bundled and no
     * repository convergence runs.
     */
    public function acquireDiscovery(TopologyRequest $request): FeatureTopology
    {
        $this->validateRequestOwnership($request);
        $operation = $this->commandOperation;
        $issueLock = new OperationLock($this->paths);
        if (! $issueLock->acquire('topology-'.$request->issue, $operation)) {
            throw new RuntimeException('The issue topology is locked.');
        }

        try {
            return $this->acquirePinned(
                $request,
                TopologyTarget::feature($request->issue, $this->mintAttempt()),
                $operation,
            );
        } finally {
            $issueLock->release();
        }
    }

    public function sync(string $issue, AttemptId $attempt, string $worktree): FeatureTopology
    {
        $request = new TopologyRequest($issue, $worktree);
        $this->validateRequestOwnership($request);
        $operation = $this->commandOperation;
        $lock = new OperationLock($this->paths);
        if (! $lock->acquire('topology-'.$request->issue, $operation)) {
            throw new RuntimeException('The issue topology is locked.');
        }
        try {
            return $this->syncUnlocked($request, $attempt, true);
        } finally {
            $lock->release();
        }
    }

    private function syncUnlocked(
        TopologyRequest $request,
        AttemptId $attempt,
        bool $allowInterrupted = false,
    ): FeatureTopology {
        $topology = $allowInterrupted
            ? $this->requireTopologyForSync($request->issue, $attempt)
            : $this->requireTopology($request->issue, $attempt);
        $target = $topology->target;
        // The lease keeps the acquiring operation: Incus resources are stamped
        // with it, and release verifies ownership against that exact value.
        // The synchronizing operation is recorded in the manifest source.
        $lease = $this->state->read('leases/'.$request->issue.'.json');
        $operationId = $lease['operation_id'] ?? null;
        if (! is_string($operationId) || preg_match('/\A[a-f0-9]{32}\z/D', $operationId) !== 1) {
            throw new RuntimeException('The topology lease has no valid acquiring operation.');
        }
        $this->writeLease($request->issue, $topology->attempt, $operationId, 'syncing');
        try {
            $this->assertColdBaseMatchesMain($request->worktree);
            $this->networks->reconcile($target->network());
            $source = $this->synchronizeSource($topology, $request->worktree);
            $verification = $this->verifier->verify($target, VerificationMode::Readiness, $source);
            if (! $verification->passed) {
                throw new RuntimeException('Feature topology verification failed.'.$verification->failedSummary());
            }

            $updated = new FeatureTopology(
                $target,
                $topology->purpose,
                $topology->generation,
                $topology->network,
                $topology->instances,
                $source,
                $verification,
                $topology->mounts,
            );
            $this->manifests->writeActive($updated);
            $this->writeLease($request->issue, $topology->attempt, $operationId, 'ready');

            return $updated;
        } catch (Throwable $exception) {
            $this->writeLease($request->issue, $topology->attempt, $operationId, 'failed');
            throw $exception;
        }
    }

    /**
     * A mounted topology reads the host worktree directly, so only its identity is
     * re-recorded; a transferred checkout receives the source and converges again.
     */
    private function synchronizeSource(FeatureTopology $topology, string $worktree): SourceState
    {
        $target = $topology->target;
        if ($topology->source->mounted) {
            $this->assertWorktreeIsMounted($topology, $worktree);
            $this->guests->assertSourceMounted($target);

            return $this->synchronizer->syncWorkingTree($target, $worktree);
        }

        $source = $this->synchronizer->sync($target, $worktree);
        $this->converger->converge($target, $source, $topology->generation->laravel);

        return $source;
    }

    /** A mounted topology serves exactly one host worktree; another path cannot be synchronized into it. */
    private function assertWorktreeIsMounted(FeatureTopology $topology, string $worktree): void
    {
        foreach ($topology->mounts as $mount) {
            if ($mount['source'] !== $worktree) {
                throw new RuntimeException('The worktree is not the source mounted on the topology attempt.');
            }
        }
    }

    public function verify(string $issue, AttemptId $attempt): FeatureTopology
    {
        TopologyTarget::assertIssue($issue);
        $lock = new OperationLock($this->paths);
        if (! $lock->acquire('topology-'.$issue, $this->commandOperation)) {
            throw new RuntimeException('The issue topology is locked.');
        }
        try {
            $topology = $this->requireTopology($issue, $attempt);
            $target = $topology->target;
            $this->networks->reconcile($target->network());
            if ($topology->source->mounted) {
                $this->guests->assertSourceMounted($target);
            }
            $report = $this->verifier->verify($target, VerificationMode::Readiness, $topology->source);
            if (! $report->passed) {
                throw new RuntimeException('Feature topology verification failed.'.$report->failedSummary());
            }

            $updated = new FeatureTopology(
                $target,
                $topology->purpose,
                $topology->generation,
                $topology->network,
                $topology->instances,
                $topology->source,
                $report,
                $topology->mounts,
            );
            $this->manifests->writeActive($updated);

            return $updated;
        } finally {
            $lock->release();
        }
    }

    /** @param list<string> $argv */
    public function execute(
        string $issue,
        AttemptId $attempt,
        string $role,
        array $argv,
        ?string $stdin = null,
    ): GuestCommandResult {
        TopologyTarget::assertIssue($issue);
        $lock = new OperationLock($this->paths);
        if (! $lock->acquire('topology-'.$issue, $this->commandOperation)) {
            throw new RuntimeException('The issue topology is locked.');
        }

        try {
            $topology = $this->requireTopology($issue, $attempt);
            $instance = $topology->target->instance($role);
            $owned = $this->host->instance($instance);
            if (
                $owned === null
                || ($owned->metadata['user.orbit.e2e.owner'] ?? null) !== 'orbit-e2e'
                || ($owned->metadata['user.orbit.e2e.issue'] ?? null) !== $issue
                || ($owned->metadata['user.orbit.e2e.attempt'] ?? null) !== $topology->attempt->value
                || ($owned->metadata['user.orbit.e2e.generation'] ?? null) !== $topology->generation->id
                || $owned->network !== $topology->network
            ) {
                throw new RuntimeException('Incus instance identity does not match the topology manifest.');
            }
            $this->journal->append($this->commandOperation, [
                'event' => 'topology.exec',
                'state' => 'started',
                'issue' => $issue,
                'attempt' => $attempt->value,
                'role' => $role,
                'target' => $instance,
                'argv' => $this->redactor->redactArgv($argv),
            ]);
            $result = $this->host->exec($instance, new GuestCommand($argv, stdin: $stdin));
            $this->journal->append($this->commandOperation, [
                'event' => 'topology.exec',
                'state' => 'completed',
                'target' => $instance,
                'exit_code' => $result->exitCode,
                'stdout' => $this->redactor->redact($result->stdout),
                'stderr' => $this->redactor->redact($result->stderr),
            ]);

            return $result;
        } finally {
            $lock->release();
        }
    }

    public function prove(TopologyRequest $request, string $candidateSha): ProofResult
    {
        $this->validateRequestOwnership($request);
        if (preg_match('/\A[0-9a-f]{40}\z/D', $candidateSha) !== 1) {
            throw new \InvalidArgumentException('The candidate must be an exact full SHA.');
        }
        $operation = $this->commandOperation;
        $lock = new OperationLock($this->paths);
        if (! $lock->acquire('topology-'.$request->issue, $operation)) {
            throw new RuntimeException('The issue topology is locked.');
        }
        try {
            return $this->proveLocked($request, $candidateSha);
        } finally {
            $lock->release();
        }
    }

    private function proveLocked(TopologyRequest $request, string $candidateSha): ProofResult
    {
        $repository = new GitRepository($request->worktree);
        if ($repository->dirtyOverlay() !== null || $repository->commit() !== $candidateSha) {
            throw new RuntimeException('Final proof requires a clean worktree at the candidate SHA.');
        }

        $active = $this->manifests->active($request->issue) ?? throw new RuntimeException(
            'The feature topology does not exist.',
        );
        $topology = $this->syncUnlocked($request, $active->attempt);
        $target = $topology->target;
        if ($topology->source->dirty || $topology->source->hostSha !== $candidateSha) {
            throw new RuntimeException('Final source sync changed the candidate identity.');
        }
        foreach (TopologyProfile::CHECKOUT_ROLES as $role) {
            $identity = $this->host->exec($target->instance($role), new GuestCommand([
                'runuser',
                '-u',
                'orbit',
                '--',
                'env',
                'HOME=/home/orbit',
                'git',
                '-C',
                '/home/orbit/orbit',
                'rev-parse',
                '--verify',
                'HEAD^{commit}',
            ]));
            if (! $identity->successful() || trim($identity->stdout) !== $candidateSha) {
                throw new RuntimeException("The {$role} checkout is not at the candidate SHA.");
            }
            $result = $this->host->exec($target->instance($role), new GuestCommand([
                'runuser',
                '-u',
                'orbit',
                '--',
                'env',
                'HOME=/home/orbit',
                'git',
                '-C',
                '/home/orbit/orbit',
                'status',
                '--porcelain=v1',
                '--untracked-files=all',
            ]));
            if (! $result->successful() || trim($result->stdout) !== '') {
                throw new RuntimeException("The {$role} checkout is not clean at the candidate.");
            }
        }
        $automated = $this->host->exec(
            $target->instance('gateway'),
            new GuestCommand(
                [
                    'runuser',
                    '-u',
                    'orbit',
                    '--',
                    'env',
                    '-C',
                    '/home/orbit/orbit',
                    'HOME=/home/orbit',
                    '/home/orbit/orbit/bin/test',
                ],
                3_600,
            ),
        );
        if (! $automated->successful()) {
            throw new RuntimeException(
                'Candidate automated checks failed.'.$this->automatedCheckSummary($automated),
            );
        }

        $verification = $this->verifier->verify($target, VerificationMode::Proof, $topology->source);
        if (! $verification->passed) {
            throw new RuntimeException('Candidate proof probes failed.');
        }
        $tree = Process::path($request->worktree)->run(['git', 'rev-parse', '--verify', 'HEAD^{tree}']);
        $candidateTree = strtolower(trim($tree->output()));
        if ($tree->failed() || preg_match('/\A[0-9a-f]{40}\z/D', $candidateTree) !== 1) {
            throw new RuntimeException('Git could not resolve the exact candidate tree.');
        }
        $result = new ProofResult(
            $this->commandOperation->value,
            bin2hex(random_bytes(16)),
            $candidateSha,
            $candidateTree,
            $repository->effectiveTreeHash(),
            $verification,
        );
        $this->state->write('proof/'.$request->issue.'.json', $result->toArray());

        return $result;
    }

    private function acquirePinned(
        TopologyRequest $request,
        TopologyTarget $target,
        OperationId $operation,
    ): FeatureTopology {
        if ($this->hasPendingRelease($request->issue)) {
            throw new RuntimeException('The issue has a pending release finalization.');
        }
        $manifest = $this->manifests->active($request->issue);
        $lease = $this->state->read('leases/'.$request->issue.'.json');
        if (($lease['state'] ?? null) === 'acquiring') {
            if ($lease === null) {
                throw new RuntimeException('Acquiring lease data is missing.');
            }
            $acquiringOperation = $this->acquiringLeaseOperation($lease, $request->issue);
            $acquiringTarget = TopologyTarget::feature(
                $request->issue,
                $this->leaseAttempt($lease, $request->issue, 'acquiring'),
            );
            if (! $this->acquiringLeaseIsStale($lease)) {
                throw new RuntimeException('The acquiring topology lease is still owned by a live process.');
            }
            if ($manifest !== null) {
                if ($manifest->attempt->value !== $acquiringTarget->attempt?->value) {
                    throw new RuntimeException('The interrupted topology manifest belongs to another attempt.');
                }
                $this->assertInterruptedManifestLive($manifest, $acquiringOperation);
                $this->writeLease($request->issue, $manifest->attempt, $acquiringOperation->value, 'ready');

                return $manifest;
            }

            $resources = [
                $acquiringTarget->network(),
                ...array_map(
                    $acquiringTarget->instance(...),
                    TopologyProfile::ROLES,
                ),
            ];
            $observed = $this->observedResources($acquiringTarget, $resources);
            $cleanup = $this->rollbackFor($acquiringTarget)->cleanup(
                $acquiringTarget,
                $resources,
                $observed,
                $acquiringOperation,
            );
            if (array_any(
                $cleanup,
                static fn (string $result): bool => ! in_array($result, ['absent', 'removed'], true),
            )) {
                throw new RuntimeException('Interrupted topology acquisition cleanup was refused.');
            }
            $this->capacity->release($request->issue, $acquiringTarget->requireAttempt(), $acquiringOperation);
        } elseif ($manifest !== null) {
            throw new RuntimeException('The issue already has a topology manifest.');
        }
        if ($this->state->read('standby/corrupt.json') !== null) {
            throw new RuntimeException('The promoted standby is marked corrupt.');
        }
        $generation = $this->standby->promoted();
        if ($generation === null) {
            throw new RuntimeException('No promoted standby generation is available.');
        }
        $expectedGenerationId = substr($generation->mainSha, 0, 12).'-'.substr($generation->preparedFingerprint, 0, 12);
        if ($generation->id !== $expectedGenerationId) {
            throw new RuntimeException('The promoted standby fingerprint is stale or corrupt.');
        }
        $structural = $this->fingerprints->forCommit('main');
        $main = $this->fingerprints->withLaravel($structural, $generation->laravel);
        if ($structural->value !== $generation->structuralFingerprint) {
            throw new RuntimeException('The promoted standby structural fingerprint is stale.');
        }
        if ($generation->preparedFingerprint !== $main->value) {
            throw new RuntimeException('The promoted standby prepared state is stale.');
        }
        $this->assertColdBaseMatchesMain($request->worktree, $main);
        $standbyTarget = TopologyTarget::standby();
        $this->host->assertOwnedSnapshots(array_combine(
            array_map($standbyTarget->instance(...), TopologyProfile::ROLES),
            $generation->snapshots,
        ));

        $this->assertMountableWorktree($request->worktree);
        $this->assertVendorHydrated($request->worktree);

        $created = [];
        $phaseTimings = [];
        $attempt = $target->requireAttempt();
        $writtenTopology = null;
        $mounts = [];
        foreach (TopologyProfile::CHECKOUT_ROLES as $role) {
            $mounts[$role] = [
                'device' => FeatureTopology::SOURCE_DEVICE,
                'source' => $request->worktree,
                'path' => MountPath::GUEST_SOURCE,
            ];
        }
        try {
            $this->writeLease($request->issue, $attempt, $operation->value, 'acquiring');
            $networkSlot = $this->capacity->reserve($request->issue, $attempt, $operation);
            $phase = 'create.network';
            $created[] = $target->network();
            $this->measurePhase($phase, $phaseTimings, fn () => $this->networks->create(
                $target->network(),
                $networkSlot,
                [
                    'user.orbit.e2e.issue' => $request->issue,
                    'user.orbit.e2e.attempt' => $attempt->value,
                    'user.orbit.e2e.operation' => $operation->value,
                ],
            ));
            $copies = [];
            foreach (TopologyProfile::ROLES as $role) {
                $name = $target->instance($role);
                $created[] = $name;
                $copies[$role] = [
                    'source' => TopologyTarget::standby()->instance($role),
                    'snapshot' => $generation->snapshots[$role],
                    'target' => $name,
                    'metadata' => [
                        'user.orbit.e2e.issue' => $request->issue,
                        'user.orbit.e2e.attempt' => $attempt->value,
                        'user.orbit.e2e.generation' => $generation->id,
                        'user.orbit.e2e.operation' => $operation->value,
                    ],
                    'network' => $target->network(),
                    'role' => $role,
                    'topology' => $target->network(),
                    'slot' => $networkSlot,
                ];
                if (array_key_exists($role, $mounts)) {
                    $copies[$role]['mount'] = $mounts[$role];
                }
            }
            $phase = 'clone';
            $this->measurePhase(
                $phase,
                $phaseTimings,
                fn () => $this->copyPinnedSnapshots($generation, $copies, $operation),
            );
            $instances = array_map($target->instance(...), TopologyProfile::ROLES);
            $phase = 'start';
            $this->measurePhase($phase, $phaseTimings, fn () => $this->host->startAll($instances));
            $phase = 'prepare.cloned-host-state';
            $this->measurePhase($phase, $phaseTimings, fn () => $this->host->prepareClonedHostStates($instances));
            $phase = 'mount.source';
            $this->measurePhase($phase, $phaseTimings, function () use ($target): void {
                $this->guests->assertSourceMounted($target);
                $this->guests->placeGatewayEnvironment($target);
            });
            $phase = 'repair.identity';
            $this->measurePhase($phase, $phaseTimings, fn () => $this->guests->repairCloneIdentity($target));
            $phase = 'sync.source';
            $source = $this->measurePhase(
                $phase,
                $phaseTimings,
                fn () => $this->synchronizer->syncWorkingTree($target, $request->worktree),
            );
            $phase = 'verify';
            $verification = $this->measurePhase(
                $phase,
                $phaseTimings,
                fn () => $this->verifier->verify($target, VerificationMode::Readiness, $source),
            );
            if (! $verification->passed) {
                throw new RuntimeException(
                    'Feature topology readiness verification failed.'.$verification->failedSummary(),
                );
            }
            $instances = [];
            foreach (TopologyProfile::ROLES as $role) {
                $instances[$role] = $target->instance($role);
            }
            $topology = new FeatureTopology(
                $target,
                AttemptPurpose::Discovery,
                $generation,
                $target->network(),
                $instances,
                $source,
                $verification,
                $mounts,
            );
            $this->manifests->writeActive($topology);
            $writtenTopology = $topology;
            $this->writeLease($request->issue, $attempt, $operation->value, 'ready');
            $this->journal->append($operation, [
                'event' => 'topology.acquire.phases',
                'state' => 'completed',
                'issue' => $request->issue,
                'duration_ms' => $phaseTimings,
            ]);

            return $topology;
        } catch (Throwable $exception) {
            $secondaryFailures = [];
            $recoveryRequired = false;
            if ($writtenTopology !== null) {
                try {
                    $this->manifests->forgetActive($writtenTopology);
                } catch (Throwable $manifestFailure) {
                    $recoveryRequired = true;
                    $secondaryFailures[] = 'manifest deletion: '.$manifestFailure->getMessage();
                }
            }
            $cleanup = [];
            $cleanupFailed = false;
            try {
                $observed = $this->observedResources($target, $created);
                $cleanup = $this->rollbackFor($target)->cleanup($target, $created, $observed, $operation);
            } catch (Throwable $cleanupFailure) {
                $cleanupFailed = true;
                $recoveryRequired = true;
                $observed ??= [];
                $cleanup = ['failed:'.$cleanupFailure->getMessage()];
                $secondaryFailures[] = 'resource cleanup: '.$cleanupFailure->getMessage();
            }
            foreach ($cleanup as $result) {
                if (! $cleanupFailed && ! in_array($result, ['absent', 'removed'], true)) {
                    $recoveryRequired = true;
                    $secondaryFailures[] =
                        'resource cleanup result: '.(is_string($result) ? $result : json_encode($result));
                }
            }
            if (! $recoveryRequired) {
                try {
                    $this->capacity->release($request->issue, $attempt, $operation);
                    $this->state->delete('leases/'.$request->issue.'.json');
                } catch (Throwable $leaseFailure) {
                    $secondaryFailures[] = 'lease deletion: '.$leaseFailure->getMessage();
                }
            }
            $redactedSecondaryFailures = array_values(array_unique(array_map(
                $this->redactor->redact(...),
                $secondaryFailures,
            )));
            try {
                $this->state->write('failures/'.$request->issue.'.json', [
                    'schema' => 2,
                    'operation_id' => $operation->value,
                    'issue' => $request->issue,
                    'attempt' => $attempt->value,
                    'resources' => $created,
                    'phase' => $phase ?? 'preflight',
                    'duration_ms' => $phaseTimings,
                    'observed' => $this->redactor->redactArray($observed ?? []),
                    'cleanup' => array_map($this->redactor->redact(...), $cleanup),
                    'error' => $this->redactor->redact($exception->getMessage()),
                    'secondary_failures' => $redactedSecondaryFailures,
                ]);
            } catch (Throwable $evidenceFailure) {
                $secondaryFailures[] = 'failure evidence write: '.$evidenceFailure->getMessage();
            }
            if ($secondaryFailures === []) {
                throw $exception;
            }

            $redactedSecondaryFailures = array_values(array_unique(array_map(
                $this->redactor->redact(...),
                $secondaryFailures,
            )));

            throw new RuntimeException(
                $this->redactor->redact('Topology acquisition failed: '.$exception->getMessage())
                    .'; secondary failures: '
                    .implode('; ', $redactedSecondaryFailures),
                previous: $exception,
            );
        }
    }

    private function mintAttempt(): AttemptId
    {
        if ($this->attempts === null) {
            return AttemptId::generate();
        }

        return ($this->attempts)();
    }

    /** Any attempt of the issue whose release finalization was interrupted blocks a new attempt. */
    private function hasPendingRelease(string $issue): bool
    {
        // The previous harness kept one flat pending record per issue; it names no
        // attempt, so only that harness can finalize it.
        $legacy = $this->paths->path('release-pending/'.$issue.'.json');
        if (file_exists($legacy) || is_link($legacy)) {
            throw new RuntimeException(
                'The issue has a legacy pending record; release with the previous harness.',
            );
        }
        $directory = $this->paths->path('release-pending/'.$issue);
        if (! file_exists($directory)) {
            return false;
        }
        if (! is_dir($directory) || is_link($directory)) {
            throw new RuntimeException('The pending release collection cannot be inspected.');
        }
        $pending = glob($directory.'/*.json');
        if ($pending === false) {
            throw new RuntimeException('The pending release collection cannot be inspected.');
        }

        return $pending !== [];
    }

    /** The worktree becomes an Incus disk source verbatim, so it must satisfy the mount path rule. */
    private function assertMountableWorktree(string $worktree): void
    {
        if (! MountPath::isMountableDirectory($worktree)) {
            throw new RuntimeException(
                'The worktree cannot be mounted: it must be an existing absolute directory path '
                .'without symlinks, commas, equals signs, or line breaks.',
            );
        }
    }

    /** Discovery guests never run composer, so the host worktree must already carry every vendor tree. */
    private function assertVendorHydrated(string $worktree): void
    {
        foreach (self::REQUIRED_VENDOR_AUTOLOADS as $autoload) {
            if (! is_file($worktree.'/'.$autoload)) {
                throw new RuntimeException(
                    "The worktree is missing {$autoload}; run bin/bootstrap in the worktree before discovery.",
                );
            }
        }
    }

    /**
     * @template T
     * @param array<string, float> $timings
     * @param callable(): T $operation
     * @return T
     */
    private function measurePhase(string $phase, array &$timings, callable $operation): mixed
    {
        $started = hrtime(true);
        try {
            return $operation();
        } finally {
            $timings[$phase] = round((hrtime(true) - $started) / 1_000_000, 3);
        }
    }

    private function acquiringLeaseOperation(array $lease, string $issue): OperationId
    {
        return $this->leaseOperation($lease, $issue, ['acquiring'], 'acquiring');
    }

    /** Cleanup is safe only when the recorded owner PID and /proc start time are dead or changed. */
    private function acquiringLeaseIsStale(array $lease): bool
    {
        $owner = [
            'pid' => $lease['pid'] ?? null,
            'process_start_identity' => $lease['process_start_identity'] ?? null,
            'operation_id' => $lease['operation_id'] ?? null,
            'acquired_at' => $lease['acquired_at'] ?? null,
        ];

        return OperationLock::isStale($owner);
    }

    /**
     * Hold the shared generation pin only across the exact snapshot copy.
     * `copySnapshots()` repeats source and snapshot ownership checks while the
     * pin is held, so refresh cannot replace the source between proof and copy.
     *
     * @param array<string, array{source:string,snapshot:string,target:string,metadata:array<string, string>,network?:string,role?:string,topology?:string,slot?:int,mount?:array{device:string,source:string,path:string}}> $copies
     */
    private function copyPinnedSnapshots(
        \App\E2E\Value\StandbyGeneration $generation,
        array $copies,
        OperationId $operation,
    ): void {
        $lock = new OperationLock($this->paths);
        if (! $lock->acquire('standby-generation', $operation, exclusive: false, timeoutSeconds: 3600)) {
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

    private function assertInterruptedManifestLive(FeatureTopology $manifest, OperationId $operation): void
    {
        $target = $manifest->target;
        $expectedNames = [];
        foreach (TopologyProfile::ROLES as $role) {
            $expectedNames[$role] = $target->instance($role);
            if (($manifest->instances[$role] ?? null) !== $expectedNames[$role]) {
                throw new RuntimeException('Interrupted topology manifest resource identity changed.');
            }
        }
        if ($manifest->network !== $target->network()) {
            throw new RuntimeException('Interrupted topology manifest network identity changed.');
        }

        $instances = $this->host->instances(array_values($expectedNames));
        if (count($instances) !== count($expectedNames)) {
            throw new RuntimeException('Interrupted topology live inventory is incomplete.');
        }
        foreach ($expectedNames as $role => $name) {
            $instance = $instances[$name] ?? null;
            if (
                $instance === null
                || ! $instance->isRunning()
                || $instance->network !== $manifest->network
                || $instance->mac !== $target->mac($role)
                || ($instance->metadata['user.orbit.e2e.owner'] ?? null) !== 'orbit-e2e'
                || ($instance->metadata['user.orbit.e2e.issue'] ?? null) !== $target->issue
                || ($instance->metadata['user.orbit.e2e.attempt'] ?? null) !== $manifest->attempt->value
                || ($instance->metadata['user.orbit.e2e.generation'] ?? null) !== $manifest->generation->id
                || ($instance->metadata['user.orbit.e2e.operation'] ?? null) !== $operation->value
            ) {
                throw new RuntimeException('Interrupted topology live instance identity does not match the manifest.');
            }
        }

        $network = $this->host->network($manifest->network);
        if (
            $network === null
            || ($network->metadata['user.orbit.e2e.owner'] ?? null) !== 'orbit-e2e'
            || ($network->metadata['user.orbit.e2e.issue'] ?? null) !== $target->issue
            || ($network->metadata['user.orbit.e2e.attempt'] ?? null) !== $manifest->attempt->value
            || ($network->metadata['user.orbit.e2e.operation'] ?? null) !== $operation->value
        ) {
            throw new RuntimeException('Interrupted topology live network identity does not match the manifest.');
        }
    }

    private function requireTopology(string $issue, AttemptId $attempt): FeatureTopology
    {
        $lease = $this->state->read('leases/'.$issue.'.json');
        if (! is_array($lease)) {
            throw new RuntimeException('The topology lease is invalid.');
        }
        if (($lease['state'] ?? null) !== 'ready') {
            throw new RuntimeException('The feature topology lease is not ready.');
        }
        $this->leaseOperation($lease, $issue, ['ready'], 'topology');

        return $this->exactTopology($issue, $attempt, $this->leaseAttempt($lease, $issue, 'topology'));
    }

    private function requireTopologyForSync(string $issue, AttemptId $attempt): FeatureTopology
    {
        $lease = $this->state->read('leases/'.$issue.'.json');
        if (! is_array($lease)) {
            throw new RuntimeException('The sync lease is invalid.');
        }
        $state = $lease['state'] ?? null;
        if ($state === 'ready') {
            return $this->requireTopology($issue, $attempt);
        }
        if (! in_array($state, ['syncing', 'failed'], true)) {
            throw new RuntimeException('The feature topology lease is not ready.');
        }

        $this->leaseOperation($lease, $issue, ['syncing', 'failed'], 'sync');

        return $this->exactTopology($issue, $attempt, $this->leaseAttempt($lease, $issue, 'sync'));
    }

    /**
     * The exact attempt record, the lease, and the active pointer must all name the
     * requested attempt before any command touches it; a proved attempt is immutable.
     */
    private function exactTopology(string $issue, AttemptId $attempt, AttemptId $leaseAttempt): FeatureTopology
    {
        if ($leaseAttempt->value !== $attempt->value) {
            throw new RuntimeException('The topology lease names another attempt.');
        }

        $topology = $this->manifests->read($issue, $attempt) ?? throw new RuntimeException(
            'The exact topology attempt does not exist.',
        );
        $active = $this->manifests->active($issue);
        if ($active === null || $active->attempt->value !== $attempt->value) {
            throw new RuntimeException('The topology attempt is not the active topology attempt.');
        }
        if ($this->proofs->isProved($issue, $attempt)) {
            throw new RuntimeException('The topology attempt is proved and cannot be changed.');
        }

        return $topology;
    }

    /** @param array<array-key, mixed> $lease */
    private function leaseAttempt(array $lease, string $issue, string $context): AttemptId
    {
        return $this->requireAttempt($lease['attempt'] ?? null, "The {$context} lease is invalid.");
    }

    private function requireAttempt(mixed $value, string $message): AttemptId
    {
        if (! is_string($value)) {
            throw new RuntimeException($message);
        }

        if (preg_match('/\A[0-9a-f]{32}\z/D', $value) !== 1) {
            throw new RuntimeException($message);
        }

        return new AttemptId($value);
    }

    /** @param list<string> $states */
    private function leaseOperation(array $lease, string $issue, array $states, string $context): OperationId
    {
        $expiresAt = $lease['expires_at'] ?? null;
        if (
            array_diff(array_keys($lease), [
                'schema',
                'issue',
                'attempt',
                'state',
                'operation_id',
                'expires_at',
                'pid',
                'process_start_identity',
                'acquired_at',
            ]) !== []
            || count($lease) < 6
            || ($lease['schema'] ?? null) !== 2
            || ($lease['issue'] ?? null) !== $issue
            || ! in_array($lease['state'] ?? null, $states, true)
            || ! is_string($expiresAt)
            || preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z\z/D', $expiresAt) !== 1
            || ($parsedExpiry = \DateTimeImmutable::createFromFormat('!Y-m-d\\TH:i:s\\Z', $expiresAt)) === false
            || $parsedExpiry->format('Y-m-d\\TH:i:s\\Z') !== $expiresAt
        ) {
            throw new RuntimeException("The {$context} lease is invalid.");
        }
        $operation = $lease['operation_id'] ?? null;
        if (! is_string($operation) || preg_match('/\A[0-9a-f]{32}\z/D', $operation) !== 1) {
            throw new RuntimeException("The {$context} lease is invalid.");
        }

        return new OperationId($operation);
    }

    private function validateRequestOwnership(TopologyRequest $request): void
    {
        $repository = new GitRepository($request->worktree);
        $expectedRoot = $this->repositoryRoot !== '' ? $this->repositoryRoot : dirname(__DIR__, 4);
        $expected = new GitRepository($expectedRoot);
        if ($this->gitCommonDirectory($repository->root()) !== $this->gitCommonDirectory($expected->root())) {
            throw new \InvalidArgumentException('The worktree repository identity does not match Orbit.');
        }
        if (! TopologyTarget::issueMatchesBranch($request->issue, $repository->branch())) {
            throw new \InvalidArgumentException('The worktree branch does not match the issue.');
        }
        if (function_exists('posix_geteuid') && fileowner($request->worktree) !== posix_geteuid()) {
            throw new \InvalidArgumentException('The worktree ownership does not match the current user.');
        }
    }

    private function gitCommonDirectory(string $root): string
    {
        $result = Process::path($root)->run(['git', 'rev-parse', '--git-common-dir']);
        if ($result->failed()) {
            throw new \InvalidArgumentException('Git repository identity validation failed.');
        }
        $path = trim($result->output());
        $resolved = realpath(str_starts_with($path, '/') ? $path : $root.'/'.$path);
        if ($resolved === false) {
            throw new \InvalidArgumentException('Git repository identity validation failed.');
        }

        return $resolved;
    }

    private function fingerprintsForWorktree(string $worktree): PreparedStateFingerprint
    {
        return new PreparedStateFingerprint(new GitRepository($worktree));
    }

    private function assertColdBaseMatchesMain(string $worktree, ?\App\E2E\Value\PreparedFingerprint $main = null): void
    {
        $main ??= $this->fingerprints->forCommit('main');
        $feature = $this->fingerprintsForWorktree($worktree)->forCommit();
        if (
            ($feature->manifest['cold_epoch'] ?? null) !== ($main->manifest['cold_epoch'] ?? null)
            || ($feature->manifest['base_image_alias'] ?? null) !== ($main->manifest['base_image_alias'] ?? null)
        ) {
            throw new RuntimeException('The feature prepared state changes the cold base contract.');
        }
    }

    /**
     * @param list<string> $resources
     * @return array<string, array<string, mixed>|null>
     */
    private function observedResources(TopologyTarget $target, array $resources): array
    {
        $observed = [];
        try {
            $inventory = $this->rollbackInventory($target, $resources);
            foreach ($resources as $resource) {
                $value = $inventory[$resource] ?? null;
                $observed[$resource] = $value === null
                    ? null
                    : (
                        $value instanceof IncusInstance || $value instanceof IncusNetwork
                            ? $this->rollbackIdentity($value)
                            : ['observation_error' => 'Invalid resource inventory value.']
                    );
            }
        } catch (Throwable $exception) {
            foreach ($resources as $resource) {
                $observed[$resource] = ['observation_error' => $exception->getMessage()];
            }
        }

        return $observed;
    }

    /** @return array<string, mixed> */
    private function rollbackIdentity(\App\E2E\Value\IncusInstance|\App\E2E\Value\IncusNetwork $resource): array
    {
        return [
            'remote' => $resource->remote,
            'project' => $resource->project,
            'name' => $resource->name,
            'pool' => $resource instanceof \App\E2E\Value\IncusInstance ? $resource->pool : null,
            'network' => $resource instanceof \App\E2E\Value\IncusInstance ? $resource->network : null,
            'mac' => $resource instanceof \App\E2E\Value\IncusInstance ? $resource->mac : null,
            'metadata' => $resource->metadata,
        ];
    }

    private function rollbackFor(TopologyTarget $target): AcquisitionRollback
    {
        /** @return array<string, IncusInstance|IncusNetwork|null> */
        $inventory = function (array $resources) use ($target): array {
            if (
                ! array_is_list($resources)
                || array_filter($resources, static fn (mixed $resource): bool => ! is_string($resource)) !== []
            ) {
                throw new RuntimeException('Rollback resource list is invalid.');
            }

            /** @var list<string> $resources */
            return $this->rollbackInventory($target, $resources);
        };

        return (
            /** @mago-expect analysis:less-specific-argument The validated adapter narrows resources at runtime. */
            $this->rollback ?? new AcquisitionRollback(
                $inventory,
                function (array $resources): void {
                    $this->host->stopAll($resources);
                },
                function (array $resources): void {
                    $this->host->deleteInstances($resources);
                },
                function (string $resource): void {
                    $this->networks->delete($resource);
                },
            )
        );
    }

    private function rollbackInventory(TopologyTarget $target, array $resources): array
    {
        /** @var list<string> $instances */
        $instances = array_values(array_filter(
            $resources,
            fn (string $resource): bool => $resource !== $target->network(),
        ));
        $inventory = $instances === [] ? [] : $this->host->instances($instances);
        $network = $this->host->network($target->network());
        $inventory[$target->network()] = $network;
        foreach ($instances as $instance) {
            $inventory[$instance] ??= null;
        }

        /** @var array<string, IncusInstance|IncusNetwork|null> $inventory */
        return $inventory;
    }

    private function writeLease(string $issue, AttemptId $attempt, string $operationId, string $state): void
    {
        $lease = [
            'schema' => 2,
            'issue' => $issue,
            'attempt' => $attempt->value,
            'state' => $state,
            'operation_id' => $operationId,
            'expires_at' => gmdate('Y-m-d\TH:i:s\Z', time() + 604_800),
        ];
        if ($state === 'acquiring') {
            $owner = OperationLock::currentOwner(new OperationId($operationId));
            $lease += [
                'pid' => $owner['pid'],
                'process_start_identity' => $owner['process_start_identity'],
                'acquired_at' => $owner['acquired_at'],
            ];
        }
        $this->state->write('leases/'.$issue.'.json', $lease);
    }

    /** Keep only the suite verdict lines so the failure evidence stays short and free of progress noise. */
    private function automatedCheckSummary(GuestCommandResult $result): string
    {
        $output = preg_replace('/\e\[[0-9;]*m/', '', $result->stdout."\n".$result->stderr) ?? '';
        $lines = array_values(array_filter(
            array_map(trim(...), explode("\n", $output)),
            static fn (string $line): bool => (
                preg_match(
                    '/^(\[[a-z0-9\/-]+\] (?:passed|failed)|FAILED |Tests: |.+ is not installed\.|.+:\d+$)/',
                    $line,
                ) === 1
            ),
        ));
        if ($lines === []) {
            $stderr = array_values(array_filter(array_map(trim(...), explode("\n", $result->stderr))));

            return (
                " Guest exit code {$result->exitCode} with no suite verdict."
                .($stderr === [] ? '' : ' '.implode(' | ', array_slice($stderr, -3)))
            );
        }

        return " Guest exit code {$result->exitCode}: ".implode(' | ', array_slice($lines, -12));
    }
}
