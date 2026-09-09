<?php

declare(strict_types=1);

namespace App\E2E;

use App\E2E\Git\GitRepository;
use App\E2E\State\AtomicJsonStore;
use App\E2E\State\OperationLock;
use App\E2E\State\StatePaths;
use App\E2E\Value\AttemptId;
use App\E2E\Value\AttemptPurpose;
use App\E2E\Value\LeaseTargetRecovery;
use App\E2E\Value\OperationId;
use App\E2E\Value\TopologyRecipe;
use App\E2E\Value\TopologyRequest;
use App\E2E\Value\TopologyTarget;
use RuntimeException;
use Throwable;

/**
 * Release one selected attempt of an issue and prove its resources are gone.
 *
 * Every Incus resource is checked against the attempt (owner, issue, attempt)
 * before deletion. The worktree's attempt lease and record are dropped; the
 * last proof result and the log stay. Every release ends with the orphan
 * network sweep.
 *
 * @mago-expect lint:cyclomatic-complexity,kan-defect Exact ordered cleanup keeps every ownership guard visible.
 */
final readonly class TopologyReleaser
{
    public function __construct(
        private IncusHost $host,
        private IncusNetworkLifecycle $networks,
        private StatePaths $hostPaths,
        private OperationId $operation,
        private ?OrphanNetworkSweep $sweep = null,
    ) {}

    /** @return array{state:string,issue:string,purpose:string,attempt_id:string,released:list<string>,already_absent:list<string>,networks_reaped:list<string>} */
    public function release(
        TopologyRequest $request,
        ?AttemptPurpose $purpose = null,
        ?LeaseTargetRecovery $recovery = null,
        bool $capture = false,
    ): array {
        $state = IssueState::forWorktree($request->issue, $request->worktree);
        $lock = new OperationLock($this->hostPaths);
        if (! $lock->acquire('topology-'.$request->issue, $this->operation)) {
            throw new RuntimeException('The issue topology is locked by another harness command.');
        }
        try {
            if (! $state->hasAttempt()) {
                throw new RuntimeException("{$request->issue} has no active attempt.");
            }
            $purpose ??= AttemptPurpose::Discovery;
            if ($recovery !== null) {
                $this->recoverLeaseTarget($request, $state, $purpose, $recovery);
            }

            if ($capture) {
                if ($purpose !== AttemptPurpose::Proof) {
                    throw new RuntimeException('Only proof attempts support evidence capture.');
                }
                $plan = ProofPlanFile::currentOrRetained($request, null)->plan;
                $evidence = ProofEvidence::capture($state, $plan);
                $archive = new AtomicJsonStore($this->hostPaths);
                $path = 'proof-evidence/'.$request->issue.'/'.$state->attemptId($purpose)->value.'.json';
                $previous = $archive->read($path);
                if ($previous !== null && $previous !== $evidence) {
                    throw new RuntimeException('The retained proof archive is immutable.');
                }
                $archive->write($path, $evidence);
                $state->captureProof($evidence);
            }

            return $this->releaseAttempt($request, $state, $purpose, $state->attemptId($purpose), $capture);
        } finally {
            $lock->release();
        }
    }

    /**
     * Release one ordered set of captured attempts only when every current identity still matches.
     *
     * @param array<string, AttemptId> $attempts Keyed by an AttemptPurpose value.
     * @return array{state:string,issue:string,attempts:list<array{state:string,issue:string,purpose:string,attempt_id:string,released:list<string>,already_absent:list<string>,networks_reaped:list<string>}>,released:list<string>,already_absent:list<string>,networks_reaped:list<string>}
     */
    public function releaseExact(TopologyRequest $request, array $attempts): array
    {
        $state = IssueState::forWorktree($request->issue, $request->worktree);
        $lock = new OperationLock($this->hostPaths);
        if (! $lock->acquire('topology-'.$request->issue, $this->operation)) {
            throw new RuntimeException('The issue topology is locked by another harness command.');
        }
        try {
            $captured = $this->capturedAttempts($request, $state, $attempts);
            $receipts = [];
            $released = [];
            $alreadyAbsent = [];
            $networksReaped = [];
            foreach ($captured as [$purpose, $attempt]) {
                try {
                    $receipt = $this->releaseAttempt($request, $state, $purpose, $attempt);
                    $receipts[] = $receipt;
                    $released = [...$released, ...$receipt['released']];
                    $alreadyAbsent = [...$alreadyAbsent, ...$receipt['already_absent']];
                    $networksReaped = [...$networksReaped, ...$receipt['networks_reaped']];
                } catch (Throwable $exception) {
                    $completed = array_map(
                        static fn (array $receipt): string => "{$receipt['purpose']} {$receipt['attempt_id']}",
                        $receipts,
                    );
                    $context = $completed === []
                        ? 'No captured attempt completed cleanup.'
                        : 'Completed captured cleanup: '.implode(', ', $completed).'.';

                    throw new RuntimeException(
                        "Exact cleanup failed for captured {$purpose->value} attempt {$attempt->value}. {$context} {$exception->getMessage()}",
                        previous: $exception,
                    );
                }
            }

            return [
                'state' => 'released',
                'issue' => $request->issue,
                'attempts' => $receipts,
                'released' => $released,
                'already_absent' => $alreadyAbsent,
                'networks_reaped' => array_values(array_unique($networksReaped)),
            ];
        } finally {
            $lock->release();
        }
    }

    /**
     * @param array<string, AttemptId> $attempts
     * @return list<array{AttemptPurpose, AttemptId}>
     */
    private function capturedAttempts(TopologyRequest $request, IssueState $state, array $attempts): array
    {
        if ($attempts === []) {
            throw new RuntimeException("{$request->issue} has no captured attempts to release.");
        }

        $captured = [];
        foreach ($attempts as $purposeValue => $attempt) {
            $purpose = AttemptPurpose::tryFrom($purposeValue);
            if ($purpose === null || ! $attempt instanceof AttemptId) {
                throw new RuntimeException('The captured attempt cleanup set is invalid.');
            }
            if (! $state->hasAttempt($purpose)) {
                throw new RuntimeException(
                    "Captured {$purpose->value} attempt {$attempt->value} is absent; no cleanup mutation was attempted.",
                );
            }
            $current = $state->attemptId($purpose);
            if ($current->value !== $attempt->value) {
                throw new RuntimeException(
                    "Captured {$purpose->value} attempt {$attempt->value} was replaced by {$current->value}; no cleanup mutation was attempted.",
                );
            }

            $captured[] = [$purpose, $attempt];
        }

        return $captured;
    }

    /** @return array{state:string,issue:string,purpose:string,attempt_id:string,released:list<string>,already_absent:list<string>,networks_reaped:list<string>} */
    private function releaseAttempt(
        TopologyRequest $request,
        IssueState $state,
        AttemptPurpose $purpose,
        AttemptId $attempt,
        bool $capture = false,
    ): array {
        $target = $this->targetForRelease($request, $state, $purpose, $attempt);
        [$released, $absent] = $this->deleteResources($target);
        $proof = $state->proof() ?? [];
        if (
            ! $capture
            && $purpose === AttemptPurpose::Proof
            && ($proof['status'] ?? null) === 'proved'
            && ($proof['attempt_id'] ?? null) === $attempt->value
            && is_string($proof['manifest_sha256'] ?? null)
        ) {
            new GitRepository($request->worktree)->unpinProof($request->issue, $attempt);
        }
        $state->forgetAttempt($purpose);

        return [
            'state' => 'released',
            'issue' => $request->issue,
            'purpose' => $purpose->value,
            'attempt_id' => $attempt->value,
            'released' => $released,
            'already_absent' => $absent,
            'networks_reaped' => $this->sweep?->sweep() ?? [],
        ];
    }

    private function recoverLeaseTarget(
        TopologyRequest $request,
        IssueState $state,
        AttemptPurpose $purpose,
        LeaseTargetRecovery $recovery,
    ): void {
        $lease = $state->attempt($purpose);
        if ($lease['attempt_id'] !== $recovery->expectedAttempt->value) {
            throw new RuntimeException(
                "The expected {$purpose->value} attempt {$recovery->expectedAttempt->value} does not match the active lease.",
            );
        }
        if (
            array_key_exists('extension', $lease)
            && $lease['extension'] !== $recovery->extension?->value
        ) {
            throw new RuntimeException('Recovery cannot replace the lease extension target.');
        }

        $topology = $state->topology($purpose);
        if (
            $topology !== null
            && $topology->construction->extension?->value !== $recovery->extension?->value
        ) {
            throw new RuntimeException('Recovery conflicts with the complete topology target.');
        }
        if ($recovery->extension === null) {
            $extended = TopologyTarget::feature(
                $request->issue,
                $recovery->expectedAttempt,
                TopologyRecipe::extendedAppProd(),
            );
            $extraName = $extended->instance('app-prod-2');
            $extra = $this->host->instances([$extraName])[$extraName] ?? null;
            if ($extra !== null) {
                $this->assertOwnership($extra->metadata, $extended, $extraName);

                throw new RuntimeException('Recovery extension none conflicts with the exact app-prod-2 VM.');
            }
        }

        $state->recoverLeaseExtension($purpose, $recovery->expectedAttempt, $recovery->extension);
    }

    private function targetForRelease(
        TopologyRequest $request,
        IssueState $state,
        AttemptPurpose $purpose,
        AttemptId $attempt,
    ): TopologyTarget {
        $topology = $state->topology($purpose);
        if ($topology !== null) {
            return $topology->target;
        }

        $extension = $state->leaseExtension($purpose);

        return TopologyTarget::feature(
            $request->issue,
            $attempt,
            $extension?->recipe() ?? TopologyRecipe::registered(),
        );
    }

    /** @return array{list<string>, list<string>} */
    private function deleteResources(TopologyTarget $target): array
    {
        $names = array_map($target->instance(...), $target->recipe->nodeKeys());
        $observed = $this->host->instances($names);
        $released = [];
        $absent = [];
        $running = [];
        $present = [];
        foreach (array_reverse($names) as $name) {
            $instance = $observed[$name] ?? null;
            if ($instance === null) {
                $absent[] = $name;
                continue;
            }
            $this->assertOwnership($instance->metadata, $target, $name);
            $present[] = $name;
            if ($instance->isRunning()) {
                $running[] = $name;
            }
        }
        if ($running !== []) {
            $this->host->forceStopAll($running);
            foreach ($running as $name) {
                $released[] = 'stopped:'.$name;
            }
        }
        if ($present !== []) {
            $this->host->deleteInstances($present);
            foreach ($present as $name) {
                $released[] = 'deleted:'.$name;
            }
        }
        if ($this->host->instances($names) !== []) {
            throw new RuntimeException('Exact topology VMs remain after release deletion.');
        }

        $network = $this->host->network($target->network());
        if ($network === null) {
            $absent[] = $target->network();
        } else {
            $this->assertOwnership($network->metadata, $target, $target->network());
            $this->networks->delete($target->network());
            $released[] = 'deleted:'.$target->network();
            if ($this->host->network($target->network()) !== null) {
                throw new RuntimeException('The topology network remains after release deletion.');
            }
        }

        return [$released, $absent];
    }

    /** @param array<string, string> $metadata */
    private function assertOwnership(array $metadata, TopologyTarget $target, string $resource): void
    {
        if (
            ($metadata['user.orbit.e2e.owner'] ?? null) !== 'orbit-e2e'
            || ($metadata['user.orbit.e2e.issue'] ?? null) !== $target->issue
            || ($metadata['user.orbit.e2e.attempt'] ?? null) !== $target->requireAttempt()->value
        ) {
            throw new RuntimeException("Incus resource {$resource} ownership does not match the issue attempt.");
        }
    }
}
