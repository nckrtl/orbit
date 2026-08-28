<?php

declare(strict_types=1);

namespace App\E2E;

use App\E2E\Git\GitRepository;
use App\E2E\State\AtomicJsonStore;
use App\E2E\State\OperationLock;
use App\E2E\State\StatePaths;
use App\E2E\Value\FeatureTopology;
use App\E2E\Value\GuestCommand;
use App\E2E\Value\GuestCommandResult;
use App\E2E\Value\OperationId;
use App\E2E\Value\ProofResult;
use App\E2E\Value\SyncMode;
use App\E2E\Value\TopologyProfile;
use App\E2E\Value\TopologyRequest;
use App\E2E\Value\TopologyTarget;
use App\E2E\Value\VerificationMode;
use Illuminate\Support\Facades\Process;
use RuntimeException;
use Throwable;

/**
 * @mago-expect lint:excessive-parameter-list The lifecycle dependencies are explicit trust boundaries.
 * @mago-expect lint:cyclomatic-complexity,kan-defect,too-many-methods The lifecycle keeps its exact ordered operations together.
 */
final readonly class TopologyAcquirer
{
    public function __construct(
        private IncusHost $host,
        private PreparedStateFingerprint $fingerprints,
        private StandbyManifestStore $standby,
        private TopologyManifestStore $manifests,
        private WorktreeSynchronizer $synchronizer,
        private TopologyConverger $converger,
        private TopologyVerifier $verifier,
        private AtomicJsonStore $state,
        private StatePaths $paths,
        private string $repositoryRoot = '',
        private ?AcquisitionRollback $rollback = null,
    ) {}

    public function acquire(TopologyRequest $request): FeatureTopology
    {
        $this->validateRequestOwnership($request);
        $operation = new OperationId(bin2hex(random_bytes(16)));
        $issueLock = new OperationLock($this->paths);
        $standbyLock = new OperationLock($this->paths);
        if (! $issueLock->acquire('topology-'.$request->issue, $operation)) {
            throw new RuntimeException('The issue topology is locked.');
        }

        try {
            if (! $standbyLock->acquire('standby-generation', $operation, false)) {
                throw new RuntimeException('The promoted standby generation is locked.');
            }

            try {
                return $this->acquirePinned($request, $operation);
            } finally {
                $standbyLock->release();
            }
        } finally {
            $issueLock->release();
        }
    }

    public function sync(TopologyRequest $request): FeatureTopology
    {
        $this->validateRequestOwnership($request);
        $operation = new OperationId(bin2hex(random_bytes(16)));
        $lock = new OperationLock($this->paths);
        if (! $lock->acquire('topology-'.$request->issue, $operation)) {
            throw new RuntimeException('The issue topology is locked.');
        }
        try {
            return $this->syncUnlocked($request);
        } finally {
            $lock->release();
        }
    }

    private function syncUnlocked(TopologyRequest $request): FeatureTopology
    {
        $topology = $this->requireTopology($request->target);
        $this->assertColdBaseMatchesMain($request->worktree);
        $source = $this->synchronizer->sync($request->target, $request->worktree, SyncMode::Incremental);
        $this->converger->converge($request->target, $source, $topology->generation->laravel);
        $verification = $this->verifier->verify($request->target, VerificationMode::Readiness, $source);
        if (! $verification->passed) {
            throw new RuntimeException('Feature topology verification failed.');
        }

        $updated = new FeatureTopology(
            $request->target,
            $topology->generation,
            $topology->network,
            $topology->instances,
            $source,
            $verification,
        );
        $this->manifests->write($updated);
        $this->writeLease(
            $request->issue,
            $source->operationId ?? bin2hex(random_bytes(16)),
            'ready',
            $source->operationId,
        );

        return $updated;
    }

    public function verify(string $issue): FeatureTopology
    {
        $target = new TopologyTarget($issue);
        $topology = $this->requireTopology($target);
        $report = $this->verifier->verify($target, VerificationMode::Readiness, $topology->source);
        if (! $report->passed) {
            throw new RuntimeException('Feature topology verification failed.');
        }

        return new FeatureTopology(
            $target,
            $topology->generation,
            $topology->network,
            $topology->instances,
            $topology->source,
            $report,
        );
    }

    /** @param list<string> $argv */
    public function execute(string $issue, string $role, array $argv, ?string $stdin = null): GuestCommandResult
    {
        $target = new TopologyTarget($issue);
        $this->requireTopology($target);

        return $this->host->exec($target->instance($role), new GuestCommand($argv, stdin: $stdin));
    }

    public function prove(TopologyRequest $request, string $candidateSha): ProofResult
    {
        $this->validateRequestOwnership($request);
        if (preg_match('/\A[0-9a-f]{40}\z/D', $candidateSha) !== 1) {
            throw new \InvalidArgumentException('The candidate must be an exact full SHA.');
        }
        $operation = new OperationId(bin2hex(random_bytes(16)));
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

        $topology = $this->syncUnlocked($request);
        if ($topology->source->dirty || $topology->source->hostSha !== $candidateSha) {
            throw new RuntimeException('Final source sync changed the candidate identity.');
        }
        foreach (TopologyProfile::CHECKOUT_ROLES as $role) {
            $identity = $this->host->exec($request->target->instance($role), new GuestCommand([
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
            $result = $this->host->exec($request->target->instance($role), new GuestCommand([
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
            $request->target->instance('gateway'),
            new GuestCommand(
                [
                    '/home/orbit/orbit/bin/test',
                ],
                3_600,
            ),
        );
        if (! $automated->successful()) {
            throw new RuntimeException('Candidate automated checks failed.');
        }

        $verification = $this->verifier->verify($request->target, VerificationMode::Proof, $topology->source);
        if (! $verification->passed) {
            throw new RuntimeException('Candidate proof probes failed.');
        }
        $tree = Process::path($request->worktree)->run(['git', 'rev-parse', '--verify', 'HEAD^{tree}']);
        $candidateTree = strtolower(trim($tree->output()));
        if ($tree->failed() || preg_match('/\A[0-9a-f]{40}\z/D', $candidateTree) !== 1) {
            throw new RuntimeException('Git could not resolve the exact candidate tree.');
        }
        $result = new ProofResult(
            bin2hex(random_bytes(16)),
            bin2hex(random_bytes(16)),
            $candidateSha,
            $candidateTree,
            $repository->effectiveTreeHash(),
            $verification,
        );
        $this->state->write('proof/'.$request->issue.'.json', $result->toArray());

        return $result;
    }

    private function acquirePinned(TopologyRequest $request, OperationId $operation): FeatureTopology
    {
        if ($this->manifests->read($request->target) !== null) {
            throw new RuntimeException('The issue already has a topology manifest.');
        }
        if ($this->state->read('standby/corrupt.json') !== null) {
            throw new RuntimeException('The promoted standby is marked corrupt.');
        }
        $generation = $this->standby->promoted();
        if ($generation === null) {
            throw new RuntimeException('No promoted standby generation is available.');
        }
        $promoted = $this->fingerprints->forCommit($generation->mainSha, $generation->laravel);
        $expectedGenerationId = substr($generation->mainSha, 0, 12).'-'.substr($promoted->value, 0, 12);
        if (
            $generation->preparedFingerprint !== $promoted->value
            || $generation->id !== $expectedGenerationId
        ) {
            throw new RuntimeException('The promoted standby fingerprint is stale or corrupt.');
        }
        $main = $this->fingerprints->forCommit('main', $generation->laravel);
        if ($generation->preparedFingerprint !== $main->value) {
            throw new RuntimeException('The promoted standby prepared state is stale.');
        }
        $this->assertColdBaseMatchesMain($request->worktree, $main);
        $baseImageAlias = $main->manifest['base_image_alias'] ?? null;
        if (! is_string($baseImageAlias) || $baseImageAlias === '') {
            throw new RuntimeException('The prepared fingerprint has no base image alias.');
        }
        if ($generation->baseImageFingerprint !== $this->host->imageFingerprint($baseImageAlias)) {
            throw new RuntimeException('The promoted standby base image fingerprint is stale.');
        }
        foreach (TopologyProfile::ROLES as $role) {
            $this->host->assertOwnedSnapshot(
                TopologyTarget::standby()->instance($role),
                $generation->snapshots[$role],
            );
        }

        $created = [];
        try {
            $phase = 'create.network';
            $this->host->createNetwork($request->target->network(), ['ipv4.address' => 'auto', 'ipv4.nat' => 'true']);
            $created[] = $request->target->network();
            $this->host->setMetadata($request->target->network(), [
                'user.orbit.e2e.issue' => $request->issue,
                'user.orbit.e2e.operation' => $operation->value,
            ]);
            foreach (TopologyProfile::ROLES as $role) {
                $phase = 'clone.'.$role;
                $target = $request->target->instance($role);
                $this->host->copySnapshot(
                    TopologyTarget::standby()->instance($role),
                    $generation->snapshots[$role],
                    $target,
                );
                $created[] = $target;
                $copied = $this->host->instance($target);
                if ($copied === null || ($copied->metadata['user.orbit.e2e.owner'] ?? null) !== 'orbit-e2e') {
                    throw new RuntimeException('A copied feature VM did not inherit Orbit ownership metadata.');
                }
                $this->host->setMetadata($target, [
                    'user.orbit.e2e.issue' => $request->issue,
                    'user.orbit.e2e.generation' => $generation->id,
                    'user.orbit.e2e.operation' => $operation->value,
                ]);
                $this->host->setNetwork($target, $request->target->network());
            }
            foreach (TopologyProfile::ROLES as $role) {
                $this->host->setDeterministicMac($request->target->instance($role), $operation->value, $role);
                $this->host->start($request->target->instance($role));
            }
            $this->host->waitForAgents(array_map(
                $request->target->instance(...),
                TopologyProfile::ROLES,
            ));
            foreach (TopologyProfile::ROLES as $role) {
                $this->host->regenerateNetworkIdentity($request->target->instance($role));
            }
            $this->host->waitForGlobalIpv4(array_map($request->target->instance(...), TopologyProfile::ROLES));
            $phase = 'sync.source';
            $source = $this->synchronizer->sync($request->target, $request->worktree, SyncMode::Full);
            $phase = 'converge';
            $this->converger->converge($request->target, $source, $generation->laravel);
            $phase = 'verify';
            $verification = $this->verifier->verify($request->target, VerificationMode::Readiness, $source);
            if (! $verification->passed) {
                throw new RuntimeException('Feature topology readiness verification failed.');
            }
            $instances = [];
            foreach (TopologyProfile::ROLES as $role) {
                $instances[$role] = $request->target->instance($role);
            }
            $topology = new FeatureTopology(
                $request->target,
                $generation,
                $request->target->network(),
                $instances,
                $source,
                $verification,
            );
            $this->manifests->write($topology);
            $this->writeLease($request->issue, $operation->value, 'ready', $source->operationId);

            return $topology;
        } catch (Throwable $exception) {
            $observed = $this->observedResources($request->target, $created);
            $cleanup = ($this->rollback ?? new AcquisitionRollback(
                fn (string $resource): \App\E2E\Value\IncusInstance|\App\E2E\Value\IncusNetwork|null => $resource === $request->target->network()
                        ? $this->host->network($resource)
                        : $this->host->instance($resource),
                function (string $resource): void {
                    $this->host->stop($resource);
                },
                function (string $resource): void {
                    $this->host->deleteInstance($resource);
                },
                function (string $resource): void {
                    $this->host->deleteNetwork($resource);
                },
            ))->cleanup($request->target, $created, $observed, $operation);
            $this->state->write('failures/'.$request->issue.'.json', [
                'schema' => 1,
                'operation_id' => $operation->value,
                'issue' => $request->issue,
                'resources' => $created,
                'phase' => $phase ?? 'preflight',
                'observed' => $observed,
                'cleanup' => $cleanup,
                'error' => $exception->getMessage(),
            ]);
            throw $exception;
        }
    }

    private function requireTopology(TopologyTarget $target): FeatureTopology
    {
        return $this->manifests->read($target) ?? throw new RuntimeException('The feature topology does not exist.');
    }

    private function validateRequestOwnership(TopologyRequest $request): void
    {
        $repository = new GitRepository($request->worktree);
        $expectedRoot = $this->repositoryRoot !== '' ? $this->repositoryRoot : dirname(__DIR__, 4);
        $expected = new GitRepository($expectedRoot);
        if ($this->gitCommonDirectory($repository->root()) !== $this->gitCommonDirectory($expected->root())) {
            throw new \InvalidArgumentException('The worktree repository identity does not match Orbit.');
        }
        if (! str_contains(strtolower($repository->branch()), strtolower($request->issue))) {
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
     * @return array<array-key, mixed>
     */
    private function observedResources(TopologyTarget $target, array $resources): array
    {
        $observed = [];
        foreach ($resources as $resource) {
            try {
                $value = $resource === $target->network()
                    ? $this->host->network($resource)
                    : $this->host->instance($resource);
                $observed[$resource] = $value === null ? null : $this->rollbackIdentity($value);
            } catch (Throwable $exception) {
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
            'metadata' => $resource->metadata,
        ];
    }

    private function writeLease(string $issue, string $operationId, string $state, ?string $sourceOperationId): void
    {
        $existing = $this->state->read('leases/'.$issue.'.json');
        $sourceOperations = $existing['source_operation_ids'] ?? [];
        if (! is_array($sourceOperations) || ! array_is_list($sourceOperations)) {
            throw new RuntimeException('The topology lease source inventory is invalid.');
        }
        if ($sourceOperationId !== null) {
            $sourceOperations[] = $sourceOperationId;
        }
        $sourceOperations = array_values(array_unique($sourceOperations));
        $this->state->write('leases/'.$issue.'.json', [
            'schema' => 1,
            'issue' => $issue,
            'state' => $state,
            'operation_id' => $operationId,
            'source_operation_ids' => $sourceOperations,
            'expires_at' => gmdate('Y-m-d\TH:i:s\Z', time() + 604_800),
        ]);
    }
}
