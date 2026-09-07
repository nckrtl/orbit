<?php

declare(strict_types=1);

namespace App\E2E;

use App\E2E\Git\GitRepository;
use App\E2E\State\OperationLock;
use App\E2E\State\StatePaths;
use App\E2E\Value\AttemptPurpose;
use App\E2E\Value\CandidateConvergenceResult;
use App\E2E\Value\FeatureTopology;
use App\E2E\Value\GuestCommand;
use App\E2E\Value\IncusInstance;
use App\E2E\Value\OperationId;
use App\E2E\Value\ProofEquivalenceReport;
use App\E2E\Value\ProofEquivalenceResult;
use App\E2E\Value\ProofFixtures;
use App\E2E\Value\ProofInputManifest;
use App\E2E\Value\ProofPlan;
use App\E2E\Value\ProofPromotionRecord;
use App\E2E\Value\ProofResult;
use App\E2E\Value\TopologyProfile;
use App\E2E\Value\TopologyRequest;
use App\E2E\Value\TopologySnapshotGeneration;
use App\E2E\Value\TopologySnapshotIdentity;
use App\E2E\Value\TopologyTarget;
use App\E2E\Value\VerificationMode;
use RuntimeException;
use Throwable;

/**
 * Promote one issue's proved topology to the topology snapshot generation.
 *
 * The three proved VMs are stopped and copied (without snapshots) next to the
 * topology snapshot instances, re-attached to the persistent topology snapshot network with its fixed topology snapshot
 * addresses, stripped of their attempt metadata, and snapshotted as
 * `main-<generation>`. Only then is each old topology snapshot instance deleted and its
 * copy renamed into place; the manifest is promoted and the proved topology
 * is released. A failure before the swap leaves the topology snapshot untouched and the
 * proved topology stopped.
 *
 * @mago-expect lint:excessive-parameter-list The promotion dependencies are explicit trust boundaries.
 * @mago-expect lint:cyclomatic-complexity,kan-defect,too-many-methods The promotion keeps its exact ordered operations together.
 */
final readonly class TopologySnapshotPromoter
{
    /** The metadata a proved clone carries that a topology snapshot instance must not. */
    private const array ATTEMPT_METADATA = [
        'user.orbit.e2e.issue',
        'user.orbit.e2e.attempt',
        'user.orbit.e2e.generation',
    ];

    private const string COPY_SUFFIX = '-next';

    public function __construct(
        private IncusHost $host,
        private PreparedStateFingerprint $fingerprints,
        private TopologyVerifier $verifier,
        private TopologySnapshotManifestStore $manifests,
        private TopologyReleaser $releaser,
        private OperationLock $lock,
        private OperationLock $generationLock,
        private StatePaths $hostPaths,
        private GitRepository $primary,
        private OperationId $operation,
        private TopologySnapshotIdentity $identity,
        private TopologySnapshotPromotionStore $promotions,
    ) {}

    /** @return array{state:string,promotion_path:string,issue:string,attempt_id:string,generation_id:string,main_sha:string,proved_sha:string,accepted_sha:string,merged_sha:string,runtime_fingerprint:string,manifest_sha256:string,equivalence_sha256:?string,previous_generation_id:?string,released:list<string>,networks_reaped:list<string>} */
    public function promote(TopologyRequest $request, ProofPlan $plan): array
    {
        $state = IssueState::forWorktree($request->issue, $request->worktree);
        $topology = $this->provedTopology($state, $plan);
        $manifest = $this->assertProofEvidence($state, $plan);
        $candidate = $this->promotionCandidate($request, $state, $topology, $manifest, $plan);
        $promotionTopology = $candidate['promotion_path'] === 'candidate-convergence'
            ? $this->candidateTopology(
                $state,
                $candidate['accepted_sha'],
                $candidate['accepted_tree'],
                $candidate['equivalence'],
            )
            : $topology;
        $promoted = $this->manifests->promoted() ?? throw new RuntimeException(
            'There is no promoted topology snapshot generation to replace; build the topology snapshot first.',
        );
        if ($promoted->isLegacy() || $promotionTopology->generation->isLegacy()) {
            throw new RuntimeException('A legacy topology snapshot generation cannot be promoted. Refresh it first.');
        }
        $generation = $this->nextGeneration($candidate['merged_sha'], $promotionTopology, $promoted);
        $verification = $this->verifier->verify(
            $promotionTopology->target,
            VerificationMode::Proof,
            $promotionTopology->source,
            requiredAssignments: $generation->topologyAssignments ?? throw new RuntimeException(
                'The candidate generation has no assignment declaration.',
            ),
        );
        if (! $verification->passed) {
            throw new RuntimeException('The candidate topology does not satisfy its required assignments.');
        }

        if (! $this->lock->acquire('standby-refresh', $this->operation, timeoutSeconds: 3600)) {
            throw new RuntimeException('Unable to acquire the topology snapshot refresh lock.');
        }
        try {
            $this->withLock($this->generationLock, 'standby-generation', function () use (
                $request,
                $state,
                $promotionTopology,
                $promoted,
                $generation,
                $candidate,
                $manifest,
            ): void {
                $issueLock = new OperationLock($this->hostPaths);
                if (! $issueLock->acquire('topology-'.$request->issue, $this->operation)) {
                    throw new RuntimeException('The issue topology is locked by another harness command.');
                }
                try {
                    if ($this->manifests->promoted()?->toArray() !== $promoted->toArray()) {
                        throw new RuntimeException(
                            'The promoted topology snapshot generation changed before promotion.',
                        );
                    }
                    $state->requireTopology($promotionTopology->purpose);
                    $this->replaceTopologySnapshot($promotionTopology, $generation);
                } finally {
                    $issueLock->release();
                }
                $this->manifests->record($generation);
                $this->manifests->promote($generation);
                $this->forgetReplacedGenerations($generation);
                $this->promotions->record(new ProofPromotionRecord(
                    $request->issue,
                    $generation->id,
                    $candidate['proved_sha'],
                    $candidate['accepted_sha'],
                    $candidate['merged_sha'],
                    $generation->preparedFingerprint,
                    $manifest->fingerprint(),
                    $candidate['equivalence']?->fingerprint(),
                    ProofResult::now(),
                    $candidate['promotion_path'],
                ));
            });
        } finally {
            $this->lock->release();
        }

        $release = $this->releaser->release(
            $request,
            $candidate['promotion_path'] === 'candidate-convergence'
                ? AttemptPurpose::CandidateConvergence
                : AttemptPurpose::Proof,
        );
        $released = $release['released'];
        $networksReaped = $release['networks_reaped'];
        if ($state->hasAttempt(AttemptPurpose::Discovery)) {
            $discovery = $this->releaser->release($request, AttemptPurpose::Discovery);
            $released = [...$released, ...$discovery['released']];
            $networksReaped = [...$networksReaped, ...$discovery['networks_reaped']];
        }
        if ($state->hasAttempt(AttemptPurpose::Proof)) {
            $proofRelease = $this->releaser->release($request, AttemptPurpose::Proof);
            $released = [...$released, ...$proofRelease['released']];
            $networksReaped = [...$networksReaped, ...$proofRelease['networks_reaped']];
        }

        return [
            'state' => 'promoted',
            'promotion_path' => $candidate['promotion_path'],
            'issue' => $request->issue,
            'attempt_id' => $promotionTopology->attempt->value,
            'generation_id' => $generation->id,
            'main_sha' => $generation->mainSha,
            'proved_sha' => $candidate['proved_sha'],
            'accepted_sha' => $candidate['accepted_sha'],
            'merged_sha' => $candidate['merged_sha'],
            'runtime_fingerprint' => $generation->preparedFingerprint,
            'manifest_sha256' => $manifest->fingerprint(),
            'equivalence_sha256' => $candidate['equivalence']?->fingerprint(),
            'previous_generation_id' => $generation->previousGenerationId,
            'released' => $released,
            'networks_reaped' => array_values(array_unique($networksReaped)),
        ];
    }

    /** The proof must be proved and its plan must not mutate the topology. */
    private function provedTopology(IssueState $state, ProofPlan $plan): FeatureTopology
    {
        if (! $state->hasAttempt()) {
            throw new RuntimeException("{$state->issue} has no active attempt.");
        }
        if (! $state->hasAttempt(AttemptPurpose::Proof)) {
            $attempt = $state->hasAttempt(AttemptPurpose::Discovery)
                ? ' attempt '.$state->attemptId(AttemptPurpose::Discovery)->value
                : '';
            throw new RuntimeException(
                "{$state->issue}{$attempt} is not proved; only a proved topology can be promoted.",
            );
        }
        $topology = $state->requireTopology(AttemptPurpose::Proof);
        if ($topology->purpose !== AttemptPurpose::Proof || ! $state->isProved()) {
            throw new RuntimeException(
                "{$state->issue} attempt {$topology->attempt->value} is not proved; only a proved topology can be promoted.",
            );
        }
        if ($plan->extension !== null || $topology->construction->extension !== null) {
            throw new RuntimeException(
                'An extended issue topology cannot become the shared topology snapshot; refresh from merged main.',
            );
        }
        if ($plan->mutates) {
            throw new RuntimeException(
                'The proof plan declares mutates: true; a topology the plan changed cannot become the topology snapshot.',
            );
        }

        return $topology;
    }

    /** Promotion accepts only the exact complete zero-exit action sequence the current plan declares. */
    private function assertProofEvidence(IssueState $state, ProofPlan $plan): ProofInputManifest
    {
        $proof = $state->proof() ?? [];
        if (($proof['plan_sha256'] ?? null) !== $plan->fingerprint()) {
            throw new RuntimeException('The recorded proof plan does not match the promotion plan.');
        }
        $expected = array_map(
            static fn (array $action): array => [
                'id' => $action['id'],
                'node' => $action['node'],
                'exit_code' => 0,
            ],
            [...$plan->setup, ...$plan->acceptance],
        );
        if (($proof['actions'] ?? null) !== $expected) {
            throw new RuntimeException('The proof result does not contain complete zero-exit action evidence.');
        }
        $fingerprint = $proof['manifest_sha256'] ?? null;
        if (! is_string($fingerprint) || preg_match('/\A[0-9a-f]{64}\z/D', $fingerprint) !== 1) {
            throw new RuntimeException('The proof result has no proof-input manifest fingerprint.');
        }
        $raw = $state->proofInputManifest($fingerprint);
        if ($raw === null) {
            throw new RuntimeException('The proof-input manifest is missing.');
        }
        try {
            $manifest = ProofInputManifest::fromArray($raw);
        } catch (\Throwable $exception) {
            throw new RuntimeException($exception->getMessage(), previous: $exception);
        }
        if ($manifest->fingerprint() !== $fingerprint) {
            throw new RuntimeException('The proof-input manifest does not match the proof result.');
        }
        if (
            $manifest->policyVersion !== StaticProofInputPolicy::VERSION
            || $manifest->construction->toArray() !== $state->requireTopology(AttemptPurpose::Proof)->construction->toArray()
            || $plan->observedInputs !== ($manifest->observedInputs !== null)
            || in_array(false, $manifest->completeness, true)
        ) {
            throw new RuntimeException('The proof-input manifest is not complete under the current policy.');
        }

        return $manifest;
    }

    /**
     * Resolve the proved, reviewed, and merged identities for the retained-proof path.
     *
     * @return array{proved_sha:string,accepted_sha:string,accepted_tree:string,merged_sha:string,promotion_path:string,equivalence:?ProofEquivalenceReport}
     */
    private function promotionCandidate(
        TopologyRequest $request,
        IssueState $state,
        FeatureTopology $topology,
        ProofInputManifest $manifest,
        ProofPlan $plan,
    ): array {
        $proof = $state->proof() ?? [];
        $candidate = $proof['candidate_sha'] ?? null;
        if (! is_string($candidate) || preg_match('/\A[a-f0-9]{40}\z/D', $candidate) !== 1) {
            throw new RuntimeException('The proof result has no candidate SHA.');
        }
        if ($topology->source->hostSha !== $candidate || $topology->source->guestSha !== $candidate) {
            throw new RuntimeException('The proved topology does not hold the proof candidate.');
        }
        if ($manifest->provedSha !== $candidate) {
            throw new RuntimeException('The proof-input manifest names a different proved candidate.');
        }
        $acceptedRepository = new GitRepository($request->worktree);
        $accepted = $acceptedRepository->commit();
        $main = $this->primary->commit('main');
        if ($this->primary->tree($main) !== $acceptedRepository->tree($accepted)) {
            throw new RuntimeException(
                "main is at {$main}, which does not hold the accepted candidate {$accepted}; merge it first.",
            );
        }
        $equivalence = null;
        if (
            $accepted !== $candidate
            && $acceptedRepository->tree($accepted) !== $acceptedRepository->tree($candidate)
        ) {
            $raw = $state->equivalence();
            if ($raw === null) {
                throw new RuntimeException('The accepted candidate has no retained-proof equivalence report.');
            }
            try {
                $equivalence = ProofEquivalenceReport::fromArray($raw);
            } catch (\Throwable $exception) {
                throw new RuntimeException($exception->getMessage(), previous: $exception);
            }
            if (
                $equivalence->provedSha !== $candidate
                || $equivalence->acceptedSha !== $accepted
                || $equivalence->planSha256 !== $plan->fingerprint()
                || $equivalence->manifestSha256 !== $manifest->fingerprint()
                || $equivalence->result !== ProofEquivalenceResult::Equivalent
                || ! in_array($equivalence->promotionPath, ['retained-proof', 'candidate-convergence'], true)
                || $equivalence->errors !== []
            ) {
                throw new RuntimeException('The equivalence report does not authorize retained-proof promotion.');
            }
        }
        $acceptedFingerprint = $this->fingerprints->forCommit($accepted)->value;
        $mergedFingerprint = $this->fingerprints->forCommit($main)->value;
        $promotionPath = $equivalence?->promotionPath ?? 'retained-proof';
        if ($promotionPath === 'candidate-convergence' && $manifest->observedInputs === null) {
            throw new RuntimeException('Candidate-convergence promotion requires complete observed-input evidence.');
        }
        if ($acceptedFingerprint !== $mergedFingerprint) {
            throw new RuntimeException('The accepted and merged runtime fingerprints differ.');
        }
        if (
            $promotionPath === 'retained-proof'
            && $this->fingerprints->forCommit($candidate)->value !== $acceptedFingerprint
        ) {
            throw new RuntimeException('The proved, accepted, and merged runtime fingerprints differ.');
        }

        return [
            'proved_sha' => $candidate,
            'accepted_sha' => $accepted,
            'accepted_tree' => $acceptedRepository->tree($accepted),
            'merged_sha' => $main,
            'promotion_path' => $promotionPath,
            'equivalence' => $equivalence,
        ];
    }

    private function candidateTopology(
        IssueState $state,
        string $acceptedSha,
        string $acceptedTree,
        ?ProofEquivalenceReport $equivalence,
    ): FeatureTopology {
        if ($equivalence === null || ! $state->hasAttempt(AttemptPurpose::CandidateConvergence)) {
            throw new RuntimeException('Candidate-convergence promotion has no active candidate topology.');
        }
        $topology = $state->requireTopology(AttemptPurpose::CandidateConvergence);
        $result = CandidateConvergenceResult::fromArray(
            $state->candidateConvergence() ?? throw new RuntimeException(
                'Candidate-convergence promotion has no convergence evidence.',
            ),
        );
        if (
            $topology->purpose !== AttemptPurpose::CandidateConvergence
            || $topology->source->hostSha !== $acceptedSha
            || $topology->source->guestSha !== $acceptedSha
            || ! $topology->verification->passed
            || $result->status !== 'converged'
            || $result->attempt->value !== $topology->attempt->value
            || $result->candidateSha !== $acceptedSha
            || $result->candidateTree !== $acceptedTree
            || $result->equivalenceSha256 !== $equivalence->fingerprint()
            || $result->convergence?->converged !== true
            || ! $result->verification->passed
        ) {
            throw new RuntimeException(
                'Candidate-convergence evidence is incomplete or does not hold the accepted candidate.',
            );
        }

        return $topology;
    }

    /**
     * The generation the proved candidate becomes: fingerprinted like a refresh
     * of that commit with the Laravel pin the proof converged with.
     */
    private function nextGeneration(
        string $candidate,
        FeatureTopology $topology,
        TopologySnapshotGeneration $promoted,
    ): TopologySnapshotGeneration {
        $structural = $this->fingerprints->forCommit($candidate);
        $laravel = $topology->generation->laravel;
        $desired = $this->fingerprints->withLaravel($structural, $laravel);
        $manifest = $desired->manifest;
        if (
            ($manifest['cold_epoch'] ?? null) !== $promoted->coldEpoch
            || ($manifest['base_image_alias'] ?? null) !== $promoted->baseImageAlias
        ) {
            throw new RuntimeException('Cold base changed; recovery-required cold topology snapshot rebuild.');
        }
        if (
            ! is_int($manifest['schema'] ?? null)
            || ! is_string($manifest['cold_epoch'] ?? null)
            || ! is_string($manifest['base_image_alias'] ?? null)
            || ! is_array($manifest['topology'] ?? null)
            || ! is_string($manifest['topology']['profile'] ?? null)
            || ! is_array($manifest['topology']['roles'] ?? null)
            || ! is_array($manifest['topology']['checkout_roles'] ?? null)
            || ! is_array($manifest['topology']['assignments'] ?? null)
        ) {
            throw new RuntimeException('The prepared fingerprint manifest has an invalid topology shape.');
        }
        $id = substr($candidate, 0, 12).'-'.substr($desired->value, 0, 12);
        // A refresh of the same commit already promoted this identity; re-promoting keeps its lineage.
        $previous = $id === $promoted->id ? $promoted->previousGenerationId : $promoted->id;
        /** @var list<string> $roles */
        $roles = array_values(array_map(strval(...), $manifest['topology']['roles']));
        /** @var list<string> $checkoutRoles */
        $checkoutRoles = array_values(array_map(strval(...), $manifest['topology']['checkout_roles']));
        /** @var array<string, list<string>> $assignments */
        $assignments = $manifest['topology']['assignments'];

        return new TopologySnapshotGeneration(
            $id,
            $candidate,
            array_fill_keys(TopologyProfile::ROLES, 'main-'.$id),
            $desired->value,
            $promoted->baseImageFingerprint,
            $laravel,
            $structural->value,
            $manifest['schema'],
            $manifest['cold_epoch'],
            $manifest['base_image_alias'],
            $manifest['topology']['profile'],
            $roles,
            $checkoutRoles,
            $previous,
            $assignments,
        );
    }

    /**
     * Remove the issue's proof fixtures while the proved instances still run, so the
     * promoted generation carries none of them into the next issue's proof.
     *
     * @param list<string> $instances
     */
    private function clearProofFixtures(array $instances): void
    {
        $removals = [];
        foreach ($instances as $instance) {
            $removals["proof-fixtures.{$instance}"] = [
                'instance' => $instance,
                'command' => new GuestCommand(
                    ['rm', '-rf', '--', ProofFixtures::GUEST_DIRECTORY],
                    60,
                ),
            ];
        }
        foreach ($this->host->execAll($removals) as $label => $result) {
            if (! $result->successful()) {
                throw new RuntimeException(
                    "Proof fixture removal failed before promotion [{$label}]: ".trim($result->stderr),
                );
            }
        }
    }

    /** Copy, re-attach, snapshot; only then swap each topology snapshot instance for its copy. */
    private function replaceTopologySnapshot(FeatureTopology $topology, TopologySnapshotGeneration $generation): void
    {
        $topologySnapshot = TopologyTarget::topologySnapshot($this->identity);
        $proved = array_map($topology->target->instance(...), TopologyProfile::ROLES);
        $topologySnapshotNames = array_map($topologySnapshot->instance(...), TopologyProfile::ROLES);
        $copyNames = array_map(static fn (string $name): string => $name.self::COPY_SUFFIX, $topologySnapshotNames);

        $leftover = array_keys($this->host->instances($copyNames));
        if ($leftover !== []) {
            throw new RuntimeException(
                'A previous promotion left '.implode(', ', $leftover).' behind; delete it before promoting again.',
            );
        }
        $this->assertOwnedByAttempt($topology, $proved);
        $this->clearProofFixtures($proved);
        $this->host->stopAll($proved);
        $this->assertStopped($proved, 'proved');
        $this->assertStopped($topologySnapshotNames, 'topology-snapshot');

        $copies = [];
        foreach (TopologyProfile::ROLES as $index => $role) {
            $copies[$role] = [
                'source' => $proved[$index],
                'target' => $copyNames[$index],
                'metadata' => ['user.orbit.e2e.operation' => $this->operation->value],
                'network' => $topologySnapshot->network(),
                'role' => $role,
                'topology' => $topologySnapshot->network(),
                'slot' => $this->identity->slot,
            ];
        }
        try {
            $this->host->copyInstances($copies);
            $snapshots = [];
            foreach (TopologyProfile::ROLES as $index => $role) {
                $this->host->unsetMetadata($copyNames[$index], self::ATTEMPT_METADATA);
                $snapshots[$copyNames[$index]] = $generation->snapshots[$role];
            }
            $this->host->snapshotAll($snapshots);
            $this->host->assertOwnedSnapshots($snapshots);
        } catch (Throwable $exception) {
            $this->discardCopies($copyNames);

            throw new RuntimeException(
                'Topology snapshot promotion failed before the swap: '.$exception->getMessage(),
                previous: $exception,
            );
        }

        foreach (TopologyProfile::ROLES as $index => $role) {
            try {
                $this->host->deleteInstances([$topologySnapshotNames[$index]]);
                $this->host->renameInstance($copyNames[$index], $topologySnapshotNames[$index]);
            } catch (Throwable $exception) {
                throw new RuntimeException(
                    "Topology snapshot promotion failed while swapping {$role}: {$exception->getMessage()} "
                    ."Rename {$copyNames[$index]} to {$topologySnapshotNames[$index]} by hand, then run bin/e2e-topology-snapshot refresh.",
                    previous: $exception,
                );
            }
        }
        $this->host->assertOwnedSnapshots(array_combine($topologySnapshotNames, array_values($generation->snapshots)));
    }

    /** @param list<string> $copyNames */
    private function discardCopies(array $copyNames): void
    {
        try {
            $present = array_keys($this->host->instances($copyNames));
            if ($present !== []) {
                $this->host->deleteInstances($present);
            }
        } catch (Throwable) {
            // The leftover check refuses the next promotion and names what to delete.
        }
    }

    /** @param list<string> $instances */
    private function assertOwnedByAttempt(FeatureTopology $topology, array $instances): void
    {
        $observed = $this->host->instances($instances);
        foreach ($instances as $name) {
            $instance = $observed[$name] ?? null;
            if (
                $instance === null
                || ($instance->metadata['user.orbit.e2e.owner'] ?? null) !== 'orbit-e2e'
                || ($instance->metadata['user.orbit.e2e.issue'] ?? null) !== $topology->target->issue
                || ($instance->metadata['user.orbit.e2e.attempt'] ?? null) !== $topology->attempt->value
            ) {
                throw new RuntimeException("Incus instance {$name} ownership does not match the proved attempt.");
            }
        }
    }

    /** @param list<string> $instances */
    private function assertStopped(array $instances, string $label): void
    {
        for ($attempt = 0; $attempt < 20; $attempt++) {
            $observed = $this->host->instances($instances);
            if (count($observed) !== count($instances)) {
                throw new RuntimeException("A {$label} VM is missing while checking its power state.");
            }
            if (array_all($observed, static fn (IncusInstance $instance): bool => $instance->isStopped())) {
                return;
            }
            usleep(100_000);
        }

        throw new RuntimeException("The {$label} VMs did not stop within the bounded wait.");
    }

    /** The replaced instances took every earlier snapshot with them; their manifests go too. */
    private function forgetReplacedGenerations(TopologySnapshotGeneration $current): void
    {
        foreach ($this->manifests->recorded() as $generation) {
            if ($generation->id !== $current->id) {
                $this->manifests->forget($generation);
            }
        }
    }

    private function withLock(OperationLock $lock, string $name, callable $mutation): void
    {
        if (! $lock->acquire($name, $this->operation, timeoutSeconds: 3600)) {
            throw new RuntimeException("Unable to acquire the {$name} lock for topology snapshot promotion.");
        }
        try {
            $mutation();
        } finally {
            $lock->release();
        }
    }
}
