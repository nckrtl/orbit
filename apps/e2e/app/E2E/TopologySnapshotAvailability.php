<?php

declare(strict_types=1);

namespace App\E2E;

use App\E2E\Value\TopologyProfile;
use App\E2E\Value\TopologySnapshotGeneration;
use App\E2E\Value\TopologySnapshotIdentity;
use App\E2E\Value\TopologyTarget;
use RuntimeException;
use Throwable;

/**
 * Prove that the promoted generation still exists on the host
 * before anything mutates it.
 *
 * A manifest that names a snapshot or a VM the host does not have is stale, not
 * corrupt: the topology snapshot was rebuilt or replaced. The
 * failure names the command that recovers it, so the caller never reaches for
 * `incus delete`.
 */
final readonly class TopologySnapshotAvailability
{
    public function __construct(
        private IncusHost $host,
        private TopologySnapshotIdentity $identity,
        private ?TopologySnapshotReplacementStore $replacements = null,
    ) {}

    /** @throws StaleTopologySnapshotManifest when the manifest names resources the host does not hold. */
    public function assertAvailable(TopologySnapshotGeneration $generation): void
    {
        $this->assertReplacementAllows($generation);

        try {
            $this->host->assertOwnedSnapshots($this->snapshots($generation));
        } catch (Throwable $exception) {
            // Either the snapshots are gone ("do not exist") or the VMs that
            // held them are ("does not exist"). Anything else is a real fault.
            if (preg_match('/do(?:es)? not exist/', $exception->getMessage()) !== 1) {
                throw $exception;
            }

            throw new StaleTopologySnapshotManifest(
                $this->recoveryMessage($generation, $exception),
                previous: $exception,
            );
        }
    }

    private function assertReplacementAllows(TopologySnapshotGeneration $generation): void
    {
        $recovery = $this->replacements?->active();
        if ($recovery === null) {
            return;
        }
        $installation = $recovery->installation;
        $expected = $recovery->manifestPromoted
            ? $installation->newGeneration
            : $installation->oldGeneration;
        $swapInProgress = ! $recovery->manifestPromoted
            && ($recovery->oldRenamedRoles !== [] || $recovery->newRenamedRoles !== []);
        $forwardIncomplete = $recovery->manifestPromoted
            && $recovery->newRenamedRoles !== TopologyProfile::ROLES;
        if ($generation->toArray() !== $expected->toArray() || $swapInProgress || $forwardIncomplete) {
            throw new RuntimeException(
                'Topology snapshot replacement recovery is incomplete; retry the exact '
                .'`bin/e2e-topology closeout '.$installation->issue.'` command.',
            );
        }
    }

    /** @return array<string, string> */
    private function snapshots(TopologySnapshotGeneration $generation): array
    {
        $target = TopologyTarget::topologySnapshot($this->identity);
        $snapshots = [];
        foreach (TopologyProfile::ROLES as $role) {
            $snapshots[$target->instance($role)] = $generation->snapshots[$role];
        }

        return $snapshots;
    }

    private function recoveryMessage(TopologySnapshotGeneration $generation, Throwable $exception): string
    {
        return
            rtrim($exception->getMessage(), ' ')
            .' The promoted generation '
            .$generation->id
            .' is stale: the topology snapshot was rebuilt or replaced, so this manifest names resources the host no longer has.'
            .' Run `'
            .StaleTopologySnapshotManifest::RECOVERY_COMMAND
            .'` with the SHA main holds to rebuild it from the'
            .' base image; the topology snapshot is not corrupt and needs no manual incus delete.';
    }
}
