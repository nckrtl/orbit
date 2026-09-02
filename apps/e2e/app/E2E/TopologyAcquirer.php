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
use App\E2E\Value\GuestCommandResult;
use App\E2E\Value\MountPath;
use App\E2E\Value\OperationId;
use App\E2E\Value\PreparedFingerprint;
use App\E2E\Value\SourceState;
use App\E2E\Value\TopologyProfile;
use App\E2E\Value\TopologyRequest;
use App\E2E\Value\TopologySnapshotGeneration;
use App\E2E\Value\TopologySnapshotIdentity;
use App\E2E\Value\TopologyTarget;
use App\E2E\Value\VerificationMode;
use Closure;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Acquire, sync, verify, and exec on one issue's discovery topology.
 *
 * The attempt lives in `<worktree>/.e2e/`. The three VMs are cloned from the
 * promoted topology snapshot, the worktree is mounted on the checkout roles, and
 * the topology stays alive until `release`.
 *
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
        private TopologySnapshotManifestStore $topologySnapshot,
        private WorktreeSynchronizer $synchronizer,
        private TopologyVerifier $verifier,
        private DiscoveryGuestPreparer $guests,
        private HostCapacity $capacity,
        private StatePaths $hostPaths,
        private OperationId $operation,
        private TopologySnapshotIdentity $topologySnapshotIdentity,
        private string $repositoryRoot = '',
        /** @var (Closure(): AttemptId)|null Mints the attempt identity; injectable so tests pin resource names. */
        private ?Closure $attempts = null,
    ) {}

    public function acquire(TopologyRequest $request): FeatureTopology
    {
        $this->assertRequestOwnership($request);
        $state = IssueState::forWorktree($request->issue, $request->worktree);
        $lock = $this->issueLock($request->issue);
        try {
            if ($state->hasAttempt()) {
                throw new RuntimeException(
                    "{$request->issue} already has attempt {$state->attemptId()->value}; release it first.",
                );
            }

            return $this->create($request, $state);
        } finally {
            $lock->release();
        }
    }

    public function sync(TopologyRequest $request): FeatureTopology
    {
        $this->assertRequestOwnership($request);
        $state = IssueState::forWorktree($request->issue, $request->worktree);
        $lock = $this->issueLock($request->issue);
        try {
            $topology = $this->mutableTopology($state);
            $this->assertColdBaseMatchesMain($request->worktree);
            $this->networks->reconcile($topology->target->network());
            $this->guests->assertSourceMounted($topology->target);
            $source = $this->synchronizer->syncWorkingTree($topology->target, $request->worktree);
            $verification = $this->verifier->verify(
                $topology->target,
                VerificationMode::Readiness,
                $source,
                requiredAssignments: $topology->generation->topologyAssignments ?? throw new RuntimeException(
                    'The pinned generation has no assignment declaration.',
                ),
            );
            if (! $verification->passed) {
                throw new RuntimeException('Feature topology verification failed.'.$verification->failedSummary());
            }
            $updated = $this->withSource($topology, $source, $verification);
            $state->writeTopology($updated);

            return $updated;
        } finally {
            $lock->release();
        }
    }

    public function verify(TopologyRequest $request): FeatureTopology
    {
        $state = IssueState::forWorktree($request->issue, $request->worktree);
        $lock = $this->issueLock($request->issue);
        try {
            $topology = $state->requireTopology();
            $this->networks->reconcile($topology->target->network());
            if ($topology->source->mounted) {
                $this->guests->assertSourceMounted($topology->target);
            }
            $report = $this->verifier->verify(
                $topology->target,
                VerificationMode::Readiness,
                $topology->source,
                requiredAssignments: $topology->generation->topologyAssignments ?? throw new RuntimeException(
                    'The pinned generation has no assignment declaration.',
                ),
            );
            if (! $report->passed) {
                throw new RuntimeException('Feature topology verification failed.'.$report->failedSummary());
            }
            $updated = $this->withSource($topology, $topology->source, $report);
            $state->writeTopology($updated);

            return $updated;
        } finally {
            $lock->release();
        }
    }

    /** @param list<string> $argv */
    public function execute(
        TopologyRequest $request,
        string $role,
        array $argv,
        ?string $stdin = null,
    ): GuestCommandResult {
        $state = IssueState::forWorktree($request->issue, $request->worktree);
        $lock = $this->issueLock($request->issue);
        try {
            $instance = $this->ownedInstance($this->mutableTopology($state), $role);

            return $this->host->exec($instance, GuestCommand::asOrbitUser($argv, stdin: $stdin));
        } finally {
            $lock->release();
        }
    }

    /** The exact VM of one role, revalidated against the record; `shell` attaches to it. */
    public function instance(TopologyRequest $request, string $role): string
    {
        $state = IssueState::forWorktree($request->issue, $request->worktree);

        return $this->ownedInstance($state->requireTopology(), $role);
    }

    private function create(TopologyRequest $request, IssueState $state): FeatureTopology
    {
        $generation = $this->promotedGeneration($request->worktree);
        $this->assertMountableWorktree($request->worktree);
        $this->assertVendorHydrated($request->worktree);

        $attempt = $this->mintAttempt();
        $target = TopologyTarget::feature($request->issue, $attempt);
        $mounts = [];
        foreach (TopologyProfile::CHECKOUT_ROLES as $role) {
            $mounts[$role] = [
                'device' => FeatureTopology::SOURCE_DEVICE,
                'source' => $request->worktree,
                'path' => MountPath::GUEST_SOURCE,
            ];
        }
        $metadata = [
            'user.orbit.e2e.issue' => $request->issue,
            'user.orbit.e2e.attempt' => $attempt->value,
            'user.orbit.e2e.operation' => $this->operation->value,
        ];
        $state->writeAttempt($attempt, AttemptPurpose::Discovery, $this->operation);
        $instances = array_map($target->instance(...), TopologyProfile::ROLES);
        try {
            $this->createResources($target, $generation, $metadata, $mounts);
            $this->host->startAll($instances);
            $this->host->prepareClonedHostStates($instances);
            $this->guests->assertSourceMounted($target);
            $this->guests->placeGatewayEnvironment($target);
            $this->guests->exposeOrbitCli($target);
            $this->guests->repairCloneIdentity($target);
            $source = $this->synchronizer->syncWorkingTree($target, $request->worktree);
            $verification = $this->verifier->verify(
                $target,
                VerificationMode::Readiness,
                $source,
                requiredAssignments: $generation->topologyAssignments ?? throw new RuntimeException(
                    'The pinned generation has no assignment declaration.',
                ),
            );
            if (! $verification->passed) {
                throw new RuntimeException(
                    'Feature topology readiness verification failed.'.$verification->failedSummary(),
                );
            }
            $topology = new FeatureTopology(
                $target,
                AttemptPurpose::Discovery,
                $generation,
                $target->network(),
                array_combine(TopologyProfile::ROLES, $instances),
                $source,
                $verification,
                $mounts,
            );
            $state->writeTopology($topology);

            return $topology;
        } catch (Throwable $exception) {
            $this->rollback($target, $state, $exception);
        }
    }

    /**
     * Network and clones exist only after this returns; the creation lock keeps
     * the orphan sweep away from a network whose first VM is not attached yet.
     *
     * @param array<string, string> $metadata
     * @param array<string, array{device:string,source:string,path:string}> $mounts
     */
    private function createResources(
        TopologyTarget $target,
        TopologySnapshotGeneration $generation,
        array $metadata,
        array $mounts,
    ): void {
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
                if (array_key_exists($role, $mounts)) {
                    $copies[$role]['mount'] = $mounts[$role];
                }
            }
            $this->copyPinnedSnapshots($generation, $copies);
        } finally {
            $creation->release();
        }
    }

    /** Roll every intended resource back, drop the lease, and rethrow. */
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
            // The lease stays so `release` can finish the cleanup.
            throw new RuntimeException(
                'Topology acquisition failed: '.$exception->getMessage().'; rollback was refused: '
                    .implode('; ', $refused),
                previous: $exception,
            );
        }
        $state->forgetAttempt();

        throw new RuntimeException('Topology acquisition failed: '.$exception->getMessage(), previous: $exception);
    }

    /** A proved attempt stays as proved: nothing may change it before release. */
    private function mutableTopology(IssueState $state): FeatureTopology
    {
        $topology = $state->requireTopology();
        if ($state->isProved()) {
            throw new RuntimeException(
                "{$state->issue} attempt {$topology->attempt->value} is proved; release it before changing it.",
            );
        }

        return $topology;
    }

    private function ownedInstance(FeatureTopology $topology, string $role): string
    {
        $instance = $topology->target->instance($role);
        $owned = $this->host->instance($instance);
        if (
            $owned === null
            || ($owned->metadata['user.orbit.e2e.owner'] ?? null) !== 'orbit-e2e'
            || ($owned->metadata['user.orbit.e2e.issue'] ?? null) !== $topology->target->issue
            || ($owned->metadata['user.orbit.e2e.attempt'] ?? null) !== $topology->attempt->value
            || $owned->network !== $topology->network
        ) {
            throw new RuntimeException('Incus instance identity does not match the topology record.');
        }

        return $instance;
    }

    private function withSource(
        FeatureTopology $topology,
        SourceState $source,
        \App\E2E\Value\VerificationReport $verification,
    ): FeatureTopology {
        return new FeatureTopology(
            $topology->target,
            $topology->purpose,
            $topology->generation,
            $topology->network,
            $topology->instances,
            $source,
            $verification,
            $topology->mounts,
        );
    }

    private function promotedGeneration(string $worktree): TopologySnapshotGeneration
    {
        $generation = $this->topologySnapshot->promoted() ?? throw new RuntimeException(
            'No promoted topology snapshot generation is available.',
        );
        if ($generation->isLegacy()) {
            throw new RuntimeException(
                'The promoted topology snapshot generation is legacy; refresh it before acquisition.',
            );
        }
        $expectedId = substr($generation->mainSha, 0, 12).'-'.substr($generation->preparedFingerprint, 0, 12);
        if ($generation->id !== $expectedId) {
            throw new RuntimeException('The promoted topology snapshot fingerprint is stale or corrupt.');
        }
        $structural = $this->fingerprints->forCommit('main');
        $main = $this->fingerprints->withLaravel($structural, $generation->laravel);
        if (
            $structural->value !== $generation->structuralFingerprint
            || $generation->preparedFingerprint !== $main->value
        ) {
            throw new RuntimeException('The promoted topology snapshot is stale; refresh it from main first.');
        }
        $this->assertColdBaseMatchesMain($worktree, $main);
        $topologySnapshotTarget = TopologyTarget::topologySnapshot($this->topologySnapshotIdentity);
        $this->host->assertOwnedSnapshots(array_combine(
            array_map($topologySnapshotTarget->instance(...), TopologyProfile::ROLES),
            $generation->snapshots,
        ));

        return $generation;
    }

    /**
     * Hold the shared generation pin only across the exact snapshot copy, so a
     * refresh cannot replace the source between the check and the copy.
     *
     * @param array<string, array{source:string,snapshot:string,target:string,metadata:array<string, string>,network?:string,role?:string,topology?:string,slot?:int,mount?:array{device:string,source:string,path:string}}> $copies
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

    private function issueLock(string $issue): OperationLock
    {
        $lock = new OperationLock($this->hostPaths);
        if (! $lock->acquire('topology-'.$issue, $this->operation)) {
            throw new RuntimeException('The issue topology is locked by another harness command.');
        }

        return $lock;
    }

    private function mintAttempt(): AttemptId
    {
        return $this->attempts === null ? AttemptId::generate() : ($this->attempts)();
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

    private function assertRequestOwnership(TopologyRequest $request): void
    {
        $repository = new GitRepository($request->worktree);
        $expectedRoot = $this->repositoryRoot !== '' ? $this->repositoryRoot : dirname(__DIR__, 4);
        if ($repository->commonDirectory() !== new GitRepository($expectedRoot)->commonDirectory()) {
            throw new InvalidArgumentException('The worktree repository identity does not match Orbit.');
        }
        if (! TopologyTarget::issueMatchesBranch($request->issue, $repository->branch())) {
            throw new InvalidArgumentException('The worktree branch does not match the issue.');
        }
        if (function_exists('posix_geteuid') && fileowner($request->worktree) !== posix_geteuid()) {
            throw new InvalidArgumentException('The worktree ownership does not match the current user.');
        }
    }

    private function assertColdBaseMatchesMain(string $worktree, ?PreparedFingerprint $main = null): void
    {
        $main ??= $this->fingerprints->forCommit('main');
        $feature = new PreparedStateFingerprint(new GitRepository($worktree))->forCommit();
        if (
            ($feature->manifest['cold_epoch'] ?? null) !== ($main->manifest['cold_epoch'] ?? null)
            || ($feature->manifest['base_image_alias'] ?? null) !== ($main->manifest['base_image_alias'] ?? null)
        ) {
            throw new RuntimeException('The feature prepared state changes the cold base contract.');
        }
    }
}
