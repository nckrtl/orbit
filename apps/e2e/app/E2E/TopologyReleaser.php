<?php

declare(strict_types=1);

namespace App\E2E;

use App\E2E\State\AtomicJsonStore;
use App\E2E\State\OperationLock;
use App\E2E\State\StatePaths;
use App\E2E\Value\AttemptId;
use App\E2E\Value\AttemptPurpose;
use App\E2E\Value\CapturedProof;
use App\E2E\Value\LeaseTargetRecovery;
use App\E2E\Value\OperationId;
use App\E2E\Value\ProofInputManifest;
use App\E2E\Value\ProofReleaseReason;
use App\E2E\Value\TopologyRecipe;
use App\E2E\Value\TopologyRequest;
use App\E2E\Value\TopologyTarget;
use Closure;
use RuntimeException;
use Throwable;

/**
 * Release one selected attempt of an issue and prove its resources are gone.
 *
 * Every Incus resource is checked against the attempt (owner, issue, attempt)
 * before deletion. The worktree's attempt lease and record are dropped; the
 * last proof result and the log stay. Every release ends with the orphan
 * network sweep.
 */
final readonly class TopologyReleaser
{
    public function __construct(
        private IncusHost $host,
        private IncusNetworkLifecycle $networks,
        private StatePaths $hostPaths,
        private OperationId $operation,
        private ?OrphanNetworkSweep $sweep = null,
        /** @var (Closure(TopologyRequest, CapturedProof): void)|null */
        private ?Closure $abandonReplacement = null,
    ) {}

    /** @return array{state:string,issue:string,purpose:string,attempt_id:string,released:list<string>,already_absent:list<string>,networks_reaped:list<string>} */
    public function release(
        TopologyRequest $request,
        ?AttemptPurpose $purpose = null,
        ?LeaseTargetRecovery $recovery = null,
        ?ProofReleaseReason $reason = null,
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
            $attempt = $state->attemptId($purpose);
            $this->assertReleaseAllowed($state, $purpose, $attempt, $reason);
            if ($purpose === AttemptPurpose::Proof && $reason === ProofReleaseReason::Abandonment) {
                $capture = $this->capturedProof($state, $attempt);
                $manifest = ProofInputManifest::fromArray($capture->manifest);
                if ($manifest->construction->snapshotReplacement) {
                    if ($this->abandonReplacement === null) {
                        throw new RuntimeException('Snapshot replacement abandonment is not configured.');
                    }
                    ($this->abandonReplacement)($request, $capture);
                }
            }

            return $this->releaseAttempt($request, $state, $purpose, $attempt);
        } finally {
            $lock->release();
        }
    }

    /**
     * Release one ordered set of captured attempts only when every current identity still matches.
     *
     * @param  array<string, AttemptId>  $attempts  Keyed by an AttemptPurpose value.
     * @return array{state:string,issue:string,attempts:list<array{state:string,issue:string,purpose:string,attempt_id:string,released:list<string>,already_absent:list<string>,networks_reaped:list<string>}>,released:list<string>,already_absent:list<string>,networks_reaped:list<string>}
     */
    public function releaseExact(
        TopologyRequest $request,
        array $attempts,
        bool $issueLockHeld = false,
    ): array {
        $state = IssueState::forWorktree($request->issue, $request->worktree);
        $lock = null;
        if (! $issueLockHeld) {
            $lock = new OperationLock($this->hostPaths);
            if (! $lock->acquire('topology-'.$request->issue, $this->operation)) {
                throw new RuntimeException('The issue topology is locked by another harness command.');
            }
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
            $lock?->release();
        }
    }

    /**
     * Closeout cleanup remains retryable if the exact lease was forgotten after
     * resource deletion but before the final closeout record was written.
     *
     * @return array{state:string,issue:string,purpose:string,attempt_id:string,released:list<string>,already_absent:list<string>,networks_reaped:list<string>}
     */
    public function releaseCapturedProof(
        TopologyRequest $request,
        CapturedProof $capture,
        bool $issueLockHeld = false,
    ): array {
        if ($capture->issue !== $request->issue) {
            throw new RuntimeException('The captured proof belongs to another issue.');
        }
        $lock = null;
        if (! $issueLockHeld) {
            $lock = new OperationLock($this->hostPaths);
            if (! $lock->acquire('topology-'.$request->issue, $this->operation)) {
                throw new RuntimeException('The issue topology is locked by another harness command.');
            }
        }

        try {
            $state = IssueState::forWorktree($request->issue, $request->worktree);
            $archived = new AtomicJsonStore($this->hostPaths)->read(
                'proof-evidence/'.$capture->issue.'/'.$capture->attempt->value.'.json',
            );
            if (
                ! is_array($archived)
                || CapturedProof::fromStoredArray($archived)->toArray() !== $capture->toArray()
            ) {
                throw new RuntimeException('The retained proof archive and cleanup capture differ.');
            }
            $active = $state->hasAttempt(AttemptPurpose::Proof);
            if ($active) {
                if ($state->attemptId(AttemptPurpose::Proof)->value !== $capture->attempt->value) {
                    throw new RuntimeException('The retained proof attempt no longer matches its capture.');
                }
                if ($state->requireTopology(AttemptPurpose::Proof)->toArray() !== $capture->topology->toArray()) {
                    throw new RuntimeException('The retained proof topology no longer matches its capture.');
                }
            }
            [$released, $absent] = $this->deleteResources($capture->topology->target);
            if ($active) {
                $state->forgetAttempt(AttemptPurpose::Proof);
            }

            return [
                'state' => 'released',
                'issue' => $request->issue,
                'purpose' => AttemptPurpose::Proof->value,
                'attempt_id' => $capture->attempt->value,
                'released' => $released,
                'already_absent' => $absent,
                'networks_reaped' => $this->sweep?->sweep() ?? [],
            ];
        } finally {
            $lock?->release();
        }
    }

    /**
     * @param  array<string, AttemptId>  $attempts
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
            if ($purpose === null) {
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
    ): array {
        $target = $this->targetForRelease($request, $state, $purpose, $attempt);
        [$released, $absent] = $this->deleteResources($target);
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

    private function assertReleaseAllowed(
        IssueState $state,
        AttemptPurpose $purpose,
        AttemptId $attempt,
        ?ProofReleaseReason $reason,
    ): void {
        if ($purpose !== AttemptPurpose::Proof) {
            if ($reason !== null) {
                throw new RuntimeException('A proof release reason can select only a proof attempt.');
            }

            return;
        }

        $proof = $state->proof() ?? [];
        $localCapture = $state->capturedProof($attempt);
        $rawArchive = new AtomicJsonStore($this->hostPaths)->read(
            'proof-evidence/'.$state->issue.'/'.$attempt->value.'.json',
        );
        $archivedCapture = is_array($rawArchive) ? CapturedProof::fromStoredArray($rawArchive) : null;
        if ($localCapture !== null && $archivedCapture === null) {
            throw new RuntimeException('The retained proof archive is missing; cleanup is refused.');
        }
        if (
            $localCapture !== null
            && $localCapture->toArray() !== $archivedCapture->toArray()
        ) {
            throw new RuntimeException('The retained proof archive and worktree capture differ.');
        }
        $successful = $localCapture !== null
            || $archivedCapture !== null
            || ($proof['status'] ?? null) === 'proved'
            && ($proof['attempt_id'] ?? null) === $attempt->value;
        if (! $successful) {
            if ($reason !== null) {
                throw new RuntimeException('Replacement and abandonment apply only to a successful proof.');
            }

            return;
        }
        if ($localCapture === null && $archivedCapture === null) {
            throw new RuntimeException('A successful proof must be captured before it can be released.');
        }
        if ($reason === null) {
            throw new RuntimeException(
                'A successful proof remains retained; select explicit replacement or abandonment, or use closeout.',
            );
        }
    }

    private function capturedProof(IssueState $state, AttemptId $attempt): CapturedProof
    {
        $local = $state->capturedProof($attempt);
        $raw = new AtomicJsonStore($this->hostPaths)->read(
            'proof-evidence/'.$state->issue.'/'.$attempt->value.'.json',
        );
        $archived = is_array($raw) ? CapturedProof::fromStoredArray($raw) : null;

        return $local ?? $archived ?? throw new RuntimeException(
            'A successful proof must be captured before its replacement can be abandoned.',
        );
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
