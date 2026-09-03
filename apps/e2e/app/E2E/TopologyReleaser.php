<?php

declare(strict_types=1);

namespace App\E2E;

use App\E2E\Git\GitRepository;
use App\E2E\State\OperationLock;
use App\E2E\State\StatePaths;
use App\E2E\Value\AttemptPurpose;
use App\E2E\Value\OperationId;
use App\E2E\Value\TopologyProfile;
use App\E2E\Value\TopologyRequest;
use App\E2E\Value\TopologyTarget;
use RuntimeException;

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
    public function release(TopologyRequest $request, ?AttemptPurpose $purpose = null): array
    {
        $state = IssueState::forWorktree($request->issue, $request->worktree);
        $lock = new OperationLock($this->hostPaths);
        if (! $lock->acquire('topology-'.$request->issue, $this->operation)) {
            throw new RuntimeException('The issue topology is locked by another harness command.');
        }
        try {
            if (! $state->hasAttempt()) {
                throw new RuntimeException("{$request->issue} has no active attempt.");
            }
            $purpose ??= $state->hasAttempt(AttemptPurpose::Discovery)
                ? AttemptPurpose::Discovery
                : (
                    $state->hasAttempt(AttemptPurpose::Proof)
                        ? AttemptPurpose::Proof
                        : AttemptPurpose::CandidateConvergence
                );
            $attempt = $state->attemptId($purpose);
            $target = TopologyTarget::feature($request->issue, $attempt);
            [$released, $absent] = $this->deleteResources($target);
            $proof = $state->proof() ?? [];
            if (
                $purpose === AttemptPurpose::Proof
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
        } finally {
            $lock->release();
        }
    }

    /** @return array{list<string>, list<string>} */
    private function deleteResources(TopologyTarget $target): array
    {
        $names = array_map($target->instance(...), TopologyProfile::ROLES);
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
