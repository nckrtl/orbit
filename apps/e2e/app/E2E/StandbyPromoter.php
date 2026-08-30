<?php

declare(strict_types=1);

namespace App\E2E;

use App\E2E\Git\GitRepository;
use App\E2E\State\OperationLock;
use App\E2E\State\StatePaths;
use App\E2E\Value\AttemptPurpose;
use App\E2E\Value\FeatureTopology;
use App\E2E\Value\IncusInstance;
use App\E2E\Value\OperationId;
use App\E2E\Value\ProofPlan;
use App\E2E\Value\StandbyGeneration;
use App\E2E\Value\TopologyProfile;
use App\E2E\Value\TopologyRequest;
use App\E2E\Value\TopologyTarget;
use RuntimeException;
use Throwable;

/**
 * Promote one issue's proved topology to the standby generation.
 *
 * The three proved VMs are stopped and copied (without snapshots) next to the
 * standby instances, re-attached to `oe-standby` with the fixed standby
 * addresses, stripped of their attempt metadata, and snapshotted as
 * `main-<generation>`. Only then is each old standby instance deleted and its
 * copy renamed into place; the manifest is promoted and the proved topology
 * is released. A failure before the swap leaves the standby untouched and the
 * proved topology stopped.
 *
 * @mago-expect lint:excessive-parameter-list The promotion dependencies are explicit trust boundaries.
 * @mago-expect lint:cyclomatic-complexity,kan-defect,too-many-methods The promotion keeps its exact ordered operations together.
 */
final readonly class StandbyPromoter
{
    /** The metadata a proved clone carries that a standby instance must not. */
    private const array ATTEMPT_METADATA = [
        'user.orbit.e2e.issue',
        'user.orbit.e2e.attempt',
        'user.orbit.e2e.generation',
    ];

    private const string COPY_SUFFIX = '-next';

    public function __construct(
        private IncusHost $host,
        private PreparedStateFingerprint $fingerprints,
        private StandbyManifestStore $manifests,
        private TopologyReleaser $releaser,
        private OperationLock $lock,
        private OperationLock $generationLock,
        private StatePaths $hostPaths,
        private GitRepository $primary,
        private OperationId $operation,
    ) {}

    /** @return array{state:string,issue:string,attempt_id:string,generation_id:string,main_sha:string,previous_generation_id:?string,released:list<string>,networks_reaped:list<string>} */
    public function promote(TopologyRequest $request, ProofPlan $plan): array
    {
        $state = IssueState::forWorktree($request->issue, $request->worktree);
        $topology = $this->provedTopology($state, $plan);
        $candidate = $this->provedCandidate($state, $topology);
        $promoted = $this->manifests->promoted() ?? throw new RuntimeException(
            'There is no promoted standby generation to replace; build the standby first.',
        );
        $generation = $this->nextGeneration($candidate, $topology, $promoted);

        if (! $this->lock->acquire('standby-refresh', $this->operation, timeoutSeconds: 3600)) {
            throw new RuntimeException('Unable to acquire the standby refresh lock.');
        }
        try {
            $this->withLock($this->generationLock, 'standby-generation', function () use (
                $request,
                $state,
                $topology,
                $promoted,
                $generation,
            ): void {
                $issueLock = new OperationLock($this->hostPaths);
                if (! $issueLock->acquire('topology-'.$request->issue, $this->operation)) {
                    throw new RuntimeException('The issue topology is locked by another harness command.');
                }
                try {
                    if ($this->manifests->promoted()?->toArray() !== $promoted->toArray()) {
                        throw new RuntimeException('The promoted standby generation changed before promotion.');
                    }
                    $state->requireTopology();
                    $this->replaceStandby($topology, $generation);
                } finally {
                    $issueLock->release();
                }
                $this->manifests->record($generation);
                $this->manifests->promote($generation);
                $this->forgetReplacedGenerations($generation);
            });
        } finally {
            $this->lock->release();
        }

        $release = $this->releaser->release($request);

        return [
            'state' => 'promoted',
            'issue' => $request->issue,
            'attempt_id' => $topology->attempt->value,
            'generation_id' => $generation->id,
            'main_sha' => $generation->mainSha,
            'previous_generation_id' => $generation->previousGenerationId,
            'released' => $release['released'],
            'networks_reaped' => $release['networks_reaped'],
        ];
    }

    /** The live attempt must be a proved proof, and its plan must not mutate the topology. */
    private function provedTopology(IssueState $state, ProofPlan $plan): FeatureTopology
    {
        $topology = $state->requireTopology();
        if ($topology->purpose !== AttemptPurpose::Proof || ! $state->isProved()) {
            throw new RuntimeException(
                "{$state->issue} attempt {$topology->attempt->value} is not proved; only a proved topology can be promoted.",
            );
        }
        if ($plan->mutates) {
            throw new RuntimeException(
                'The proof plan declares mutates: true; a topology the plan changed cannot become the standby.',
            );
        }

        return $topology;
    }

    /** The proved candidate must be exactly what `main` holds in the primary checkout. */
    private function provedCandidate(IssueState $state, FeatureTopology $topology): string
    {
        $proof = $state->proof() ?? [];
        $candidate = $proof['candidate_sha'] ?? null;
        if (! is_string($candidate) || preg_match('/\A[a-f0-9]{40}\z/D', $candidate) !== 1) {
            throw new RuntimeException('The proof result has no candidate SHA.');
        }
        if ($topology->source->hostSha !== $candidate || $topology->source->guestSha !== $candidate) {
            throw new RuntimeException('The proved topology does not hold the proof candidate.');
        }
        $main = $this->primary->commit('main');
        if ($main !== $candidate && $this->primary->tree($main) !== $this->primary->tree($candidate)) {
            throw new RuntimeException(
                "main is at {$main}, which does not hold the proved candidate {$candidate}; merge it first.",
            );
        }

        return $candidate;
    }

    /**
     * The generation the proved candidate becomes: fingerprinted like a refresh
     * of that commit with the Laravel pin the proof converged with.
     */
    private function nextGeneration(
        string $candidate,
        FeatureTopology $topology,
        StandbyGeneration $promoted,
    ): StandbyGeneration {
        $structural = $this->fingerprints->forCommit($candidate);
        $laravel = $topology->generation->laravel;
        $desired = $this->fingerprints->withLaravel($structural, $laravel);
        $manifest = $desired->manifest;
        if (
            ($manifest['cold_epoch'] ?? null) !== $promoted->coldEpoch
            || ($manifest['base_image_alias'] ?? null) !== $promoted->baseImageAlias
        ) {
            throw new RuntimeException('Cold base changed; recovery-required cold standby rebuild.');
        }
        if (
            ! is_int($manifest['schema'] ?? null)
            || ! is_string($manifest['cold_epoch'] ?? null)
            || ! is_string($manifest['base_image_alias'] ?? null)
            || ! is_array($manifest['topology'] ?? null)
            || ! is_string($manifest['topology']['profile'] ?? null)
            || ! is_array($manifest['topology']['roles'] ?? null)
            || ! is_array($manifest['topology']['checkout_roles'] ?? null)
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

        return new StandbyGeneration(
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
        );
    }

    /** Copy, re-attach, snapshot; only then swap each standby instance for its copy. */
    private function replaceStandby(FeatureTopology $topology, StandbyGeneration $generation): void
    {
        $standby = TopologyTarget::standby();
        $proved = array_map($topology->target->instance(...), TopologyProfile::ROLES);
        $standbyNames = array_map($standby->instance(...), TopologyProfile::ROLES);
        $copyNames = array_map(static fn (string $name): string => $name.self::COPY_SUFFIX, $standbyNames);

        $leftover = array_keys($this->host->instances($copyNames));
        if ($leftover !== []) {
            throw new RuntimeException(
                'A previous promotion left '.implode(', ', $leftover).' behind; delete it before promoting again.',
            );
        }
        $this->assertOwnedByAttempt($topology, $proved);
        $this->host->stopAll($proved);
        $this->assertStopped($proved, 'proved');
        $this->assertStopped($standbyNames, 'standby');

        $copies = [];
        foreach (TopologyProfile::ROLES as $index => $role) {
            $copies[$role] = [
                'source' => $proved[$index],
                'target' => $copyNames[$index],
                'metadata' => ['user.orbit.e2e.operation' => $this->operation->value],
                'network' => $standby->network(),
                'role' => $role,
                'topology' => $standby->network(),
                'slot' => 1,
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
                'Standby promotion failed before the swap: '.$exception->getMessage(),
                previous: $exception,
            );
        }

        foreach (TopologyProfile::ROLES as $index => $role) {
            try {
                $this->host->deleteInstances([$standbyNames[$index]]);
                $this->host->renameInstance($copyNames[$index], $standbyNames[$index]);
            } catch (Throwable $exception) {
                throw new RuntimeException(
                    "Standby promotion failed while swapping {$role}: {$exception->getMessage()} "
                    ."Rename {$copyNames[$index]} to {$standbyNames[$index]} by hand, then run bin/e2e-standby refresh.",
                    previous: $exception,
                );
            }
        }
        $this->host->assertOwnedSnapshots(array_combine($standbyNames, array_values($generation->snapshots)));
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
    private function forgetReplacedGenerations(StandbyGeneration $current): void
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
            throw new RuntimeException("Unable to acquire the {$name} lock for standby promotion.");
        }
        try {
            $mutation();
        } finally {
            $lock->release();
        }
    }
}
