<?php

declare(strict_types=1);

namespace App\E2E;

use App\E2E\State\AtomicJsonStore;
use App\E2E\State\OperationLock;
use App\E2E\State\StatePaths;
use App\E2E\Value\IncusInstance;
use App\E2E\Value\LegacyStandbyInventory;
use App\E2E\Value\OperationId;
use App\E2E\Value\StandbyIdentity;
use App\E2E\Value\TopologyProfile;
use Closure;
use RuntimeException;

/**
 * Rebuild this checkout's standby from the base image after its resources and
 * its manifest disagree.
 *
 * Ordinary rebuild only repairs stale state after exact resource inventory
 * proves that all configured VMs, promotion copies, and the network are absent.
 * Legacy recovery supplies a separate, hash-bound authorization before this
 * service removes any exact resource or manifest.
 *
 * @mago-expect lint:cyclomatic-complexity,excessive-parameter-list,kan-defect,too-many-methods The recovery keeps its exact resource transaction at one boundary.
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
     * Forget stale manifests only after inventory proves every configured
     * standby resource is absent, so a cold build can construct it again.
     *
     * @return array{instances_deleted:list<string>,networks_deleted:list<string>}
     */
    public function teardown(): array
    {
        if (! $this->lock->acquire('standby-refresh', $this->operation, timeoutSeconds: 3600)) {
            throw new RuntimeException('Unable to acquire the standby refresh lock.');
        }

        try {
            $present = array_keys($this->host->instances($this->standbyInstanceNames()));
            if ($this->host->network($this->identity->network()) !== null) {
                $present[] = $this->identity->network();
            }
            if ($present !== []) {
                sort($present, SORT_STRING);

                throw new RuntimeException(
                    'Standby resources are present: '
                    .implode(', ', $present)
                    .'. Use bin/e2e-standby recover-legacy --main-sha=<sha>.',
                );
            }

            $instancesDeleted = $this->deleteInstances();
            $networksDeleted = $this->deleteNetwork();
            $this->forgetManifests();

            return ['instances_deleted' => $instancesDeleted, 'networks_deleted' => $networksDeleted];
        } finally {
            $this->lock->release();
        }
    }

    /**
     * @param Closure(string, array<string, mixed>):void $record
     * @return array{instances_deleted:list<string>,networks_deleted:list<string>}
     */
    public function recover(
        LegacyStandbyInventory $authorization,
        Closure $record,
        bool $instancesMayBeAbsent = false,
        bool $networkMayBeAbsent = false,
        bool $manifestsMayBeAbsent = false,
    ): array {
        $this->assertAuthorizedScope($authorization);
        $this->assertInventoryUnchanged(
            $authorization,
            $instancesMayBeAbsent,
            $networkMayBeAbsent,
            $manifestsMayBeAbsent,
        );
        $authorizedInstances = array_keys($authorization->instances);
        $present = $this->host->instances($this->standbyInstanceNames());
        sort($authorizedInstances, SORT_STRING);
        foreach ($present as $name => $instance) {
            $this->assertHarnessOwned($instance, $name);
        }

        $record('instances_pending', ['instances' => $authorizedInstances]);
        $instancesDeleted = $this->deleteInstances();
        $record('instances_verified', ['instances_deleted' => $instancesDeleted]);

        $authorizedNetwork = $authorization->network['name'] ?? null;
        $network = $this->host->network($this->identity->network());
        if ($network !== null && $authorizedNetwork !== $network->name) {
            throw new RuntimeException('The exact standby network inventory changed after authorization.');
        }
        if ($network !== null && ($network->metadata['user.orbit.e2e.owner'] ?? null) !== 'orbit-e2e') {
            throw new RuntimeException("Incus network {$network->name} ownership does not match.");
        }
        $record('network_pending', ['network' => $authorizedNetwork]);
        $networksDeleted = $this->deleteNetwork();
        $record('network_verified', ['networks_deleted' => $networksDeleted]);

        $promoted = $this->manifests->promoted();
        if ($promoted !== null && $promoted->toArray() !== $authorization->promotedManifest) {
            throw new RuntimeException('The promoted standby manifest changed after authorization.');
        }
        foreach ($this->manifests->recorded() as $recorded) {
            if (! in_array($recorded->toArray(), $authorization->recordedManifests, true)) {
                throw new RuntimeException('A recorded standby manifest changed after authorization.');
            }
        }
        $record('manifests_pending', ['promoted_manifest' => $authorization->promotedManifest]);
        $this->forgetManifests();
        $record('manifests_verified', ['promoted_manifest_retained' => $authorization->promotedManifest]);

        return ['instances_deleted' => $instancesDeleted, 'networks_deleted' => $networksDeleted];
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

    private function assertAuthorizedScope(LegacyStandbyInventory $authorization): void
    {
        $expected = [
            ...$this->host->scope(),
            'standby_namespace' => $this->identity->namespace,
        ];
        if ($authorization->scope !== $expected) {
            throw new RuntimeException('The legacy standby recovery scope does not match this harness.');
        }
    }

    private function assertInventoryUnchanged(
        LegacyStandbyInventory $authorization,
        bool $instancesMayBeAbsent,
        bool $networkMayBeAbsent,
        bool $manifestsMayBeAbsent,
    ): void {
        $present = $this->host->instances($this->standbyInstanceNames());
        $authorizedNames = array_keys($authorization->instances);
        $presentNames = array_keys($present);
        sort($authorizedNames, SORT_STRING);
        sort($presentNames, SORT_STRING);
        if (! $instancesMayBeAbsent && $presentNames !== $authorizedNames) {
            throw new RuntimeException('The exact standby instance inventory changed after authorization.');
        }
        foreach ($present as $name => $instance) {
            $this->assertHarnessOwned($instance, $name);
            $authorized = $authorization->instances[$name] ?? null;
            if (! is_array($authorized)) {
                throw new RuntimeException('The exact standby instance inventory changed after authorization.');
            }
            $current = $this->instanceArray($instance);
            unset($authorized['status'], $authorized['status_code'], $current['status'], $current['status_code']);
            if ($current !== $authorized) {
                throw new RuntimeException('The exact standby instance inventory changed after authorization.');
            }
        }
        $snapshots = $present === [] ? [] : $this->host->ownedSnapshotNames(array_keys($present));
        foreach ($snapshots as $name => $current) {
            if (($authorization->snapshots[$name] ?? null) !== $current) {
                throw new RuntimeException('The exact standby snapshot inventory changed after authorization.');
            }
        }

        $network = $this->host->network($this->identity->network());
        if ($network === null && $authorization->network !== null && ! $networkMayBeAbsent) {
            throw new RuntimeException('The exact standby network inventory changed after authorization.');
        }
        if ($network !== null) {
            if (($network->metadata['user.orbit.e2e.owner'] ?? null) !== 'orbit-e2e') {
                throw new RuntimeException("Incus network {$network->name} ownership does not match.");
            }
            if ($authorization->network === null) {
                throw new RuntimeException('The exact standby network inventory changed after authorization.');
            }
            $current = $this->networkArray($network);
            $authorized = $authorization->network;
            $currentUsers = $network->usedBy;
            $authorizedUserInventory = $authorized['used_by'] ?? null;
            if (! is_array($authorizedUserInventory)) {
                throw new RuntimeException('The exact standby network inventory changed after authorization.');
            }
            $authorizedUsers = array_values(array_filter(
                $authorizedUserInventory,
                static fn (mixed $user): bool => (
                    is_string($user)
                    && in_array(basename((string) parse_url($user, PHP_URL_PATH)), array_keys($present), true)
                ),
            ));
            unset($current['used_by'], $authorized['used_by']);
            sort($currentUsers, SORT_STRING);
            sort($authorizedUsers, SORT_STRING);
            if ($current !== $authorized || $currentUsers !== $authorizedUsers) {
                throw new RuntimeException('The exact standby network inventory changed after authorization.');
            }
        }

        $promoted = $this->manifests->promoted();
        if ($promoted === null && ! $manifestsMayBeAbsent) {
            throw new RuntimeException('The promoted standby manifest changed after authorization.');
        }
        if ($promoted !== null && $promoted->toArray() !== $authorization->promotedManifest) {
            throw new RuntimeException('The promoted standby manifest changed after authorization.');
        }
        $recordedManifests = array_map(
            static fn ($generation): array => $generation->toArray(),
            $this->manifests->recorded(),
        );
        if (! $manifestsMayBeAbsent && $recordedManifests !== $authorization->recordedManifests) {
            throw new RuntimeException('A recorded standby manifest changed after authorization.');
        }
        foreach ($recordedManifests as $recorded) {
            if (! in_array($recorded, $authorization->recordedManifests, true)) {
                throw new RuntimeException('A recorded standby manifest changed after authorization.');
            }
        }
    }

    /** @return array<string, mixed> */
    private function instanceArray(IncusInstance $instance): array
    {
        return [
            'remote' => $instance->remote,
            'project' => $instance->project,
            'name' => $instance->name,
            'pool' => $instance->pool,
            'metadata' => $instance->metadata,
            'status' => $instance->status,
            'status_code' => $instance->statusCode,
            'network' => $instance->network,
            'mac' => $instance->mac,
            'disks' => $instance->disks,
        ];
    }

    /** @return array<string, mixed> */
    private function networkArray(\App\E2E\Value\IncusNetwork $network): array
    {
        return [
            'remote' => $network->remote,
            'project' => $network->project,
            'name' => $network->name,
            'metadata' => $network->metadata,
            'config' => $network->config,
            'used_by' => $network->usedBy,
        ];
    }
}
