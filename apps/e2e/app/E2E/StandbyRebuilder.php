<?php

declare(strict_types=1);

namespace App\E2E;

use App\E2E\State\AtomicJsonStore;
use App\E2E\State\OperationLock;
use App\E2E\State\StatePaths;
use App\E2E\Value\IncusInstance;
use App\E2E\Value\OperationId;
use App\E2E\Value\StandbyIdentity;
use App\E2E\Value\TopologyProfile;
use RuntimeException;

/**
 * Rebuild this checkout's standby from the base image after its resources and
 * its manifest disagree.
 *
 * The teardown is the recovery path that used to need `incus delete` by hand:
 * it removes the standby VMs, the copies a failed promotion left behind, the
 * standby network, and every manifest that named them. Only resources this
 * checkout's standby identity owns are touched, and only when Incus reports
 * them as harness-owned; anything else refuses the rebuild instead.
 *
 * @mago-expect lint:cyclomatic-complexity,excessive-parameter-list The recovery keeps its exact resource transaction at one boundary.
 */
final readonly class StandbyRebuilder
{
    /** The copy a promotion makes before it swaps a standby instance into place. */
    private const string COPY_SUFFIX = '-next';

    public function __construct(
        private IncusHost $host,
        private IncusNetworkLifecycle $networks,
        private StandbyManifestStore $manifests,
        private AtomicJsonStore $state,
        private StatePaths $paths,
        private OperationLock $lock,
        private OperationId $operation,
        private StandbyIdentity $identity,
    ) {}

    /**
     * Delete every resource of this standby identity and forget its manifests,
     * so a cold build can construct the standby again.
     *
     * The refresh lock is held for the teardown only: the cold build that
     * follows takes it again for itself.
     *
     * @return array{instances_deleted:list<string>,networks_deleted:list<string>}
     */
    public function teardown(): array
    {
        if (! $this->lock->acquire('standby-refresh', $this->operation, timeoutSeconds: 3600)) {
            throw new RuntimeException('Unable to acquire the standby refresh lock.');
        }

        try {
            $instancesDeleted = $this->deleteInstances();
            $networksDeleted = $this->deleteNetwork();
            $this->forgetManifests();

            return ['instances_deleted' => $instancesDeleted, 'networks_deleted' => $networksDeleted];
        } finally {
            $this->lock->release();
        }
    }

    /** @return list<string> */
    private function deleteInstances(): array
    {
        $names = $this->standbyInstanceNames();
        $present = $this->host->instances($names);
        foreach ($present as $name => $instance) {
            $this->assertHarnessOwned($instance, $name);
        }
        if ($present === []) {
            return [];
        }

        $running = array_keys(array_filter(
            $present,
            static fn (IncusInstance $instance): bool => ! $instance->isStopped(),
        ));
        if ($running !== []) {
            $this->host->stopAll($running);
        }
        $deletions = array_values(array_filter(
            array_reverse($names),
            static fn (string $name): bool => isset($present[$name]),
        ));
        $this->host->deleteInstances($deletions);
        if ($this->host->instances($names) !== []) {
            throw new RuntimeException('A standby VM persisted after deletion.');
        }

        sort($deletions, SORT_STRING);

        return $deletions;
    }

    /** @return list<string> */
    private function deleteNetwork(): array
    {
        $name = $this->identity->network();
        $network = $this->host->network($name);
        if ($network === null) {
            return [];
        }

        // A network a failed cold build left behind may carry no ownership
        // metadata; the harness prefix and the standby name are the identity.
        if (($network->metadata['user.orbit.e2e.owner'] ?? null) === 'orbit-e2e') {
            $this->networks->delete($name);
        } else {
            $this->networks->deleteOrphan($name);
        }
        if ($this->host->network($name) !== null) {
            throw new RuntimeException('The standby network persisted after deletion.');
        }

        return [$name];
    }

    /** The manifests named the deleted snapshots; nothing may promote them again. */
    private function forgetManifests(): void
    {
        foreach ($this->manifests->recorded() as $generation) {
            $this->manifests->forget($generation);
        }
        $this->state->delete('standby/promoted.json');
        $this->state->delete('standby/corrupt.json');
        if ($this->manifests->promoted() !== null) {
            throw new RuntimeException('The promoted standby manifest persisted after the teardown.');
        }
    }

    /** @return list<string> */
    private function standbyInstanceNames(): array
    {
        $names = [];
        foreach (TopologyProfile::ROLES as $role) {
            $names[] = $this->identity->instance($role);
            $names[] = $this->identity->instance($role).self::COPY_SUFFIX;
        }

        return $names;
    }

    private function assertHarnessOwned(IncusInstance $instance, string $name): void
    {
        if (($instance->metadata['user.orbit.e2e.owner'] ?? null) !== 'orbit-e2e') {
            throw new RuntimeException(
                "Incus instance {$name} is not harness-owned; the standby rebuild refuses to delete it.",
            );
        }
        $issue = $instance->metadata['user.orbit.e2e.issue'] ?? null;
        if (is_string($issue) && $issue !== '') {
            throw new RuntimeException(
                "Incus instance {$name} belongs to issue {$issue}; release that topology before rebuilding the standby.",
            );
        }
    }
}
