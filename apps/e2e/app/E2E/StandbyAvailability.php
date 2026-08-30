<?php

declare(strict_types=1);

namespace App\E2E;

use App\E2E\Value\StandbyGeneration;
use App\E2E\Value\StandbyIdentity;
use App\E2E\Value\TopologyProfile;
use App\E2E\Value\TopologyTarget;
use Throwable;

/**
 * Prove that the promoted generation of this checkout still exists on the host
 * before anything mutates it.
 *
 * A manifest that names a snapshot or a VM the host does not have is stale, not
 * corrupt: the standby was rebuilt, or promoted from another checkout. The
 * failure names the command that recovers it, so the caller never reaches for
 * `incus delete`.
 */
final readonly class StandbyAvailability
{
    public function __construct(
        private IncusHost $host,
        private StandbyIdentity $identity,
    ) {}

    /** @throws StaleStandbyManifest when the manifest names resources the host does not hold. */
    public function assertAvailable(StandbyGeneration $generation): void
    {
        try {
            $this->host->assertOwnedSnapshots($this->snapshots($generation));
        } catch (Throwable $exception) {
            // Either the snapshots are gone ("do not exist") or the VMs that
            // held them are ("does not exist"). Anything else is a real fault.
            if (preg_match('/do(?:es)? not exist/', $exception->getMessage()) !== 1) {
                throw $exception;
            }

            throw new StaleStandbyManifest($this->recoveryMessage($generation, $exception), previous: $exception);
        }
    }

    /** @return array<string, string> */
    private function snapshots(StandbyGeneration $generation): array
    {
        $target = TopologyTarget::standby($this->identity);
        $snapshots = [];
        foreach (TopologyProfile::ROLES as $role) {
            $snapshots[$target->instance($role)] = $generation->snapshots[$role];
        }

        return $snapshots;
    }

    private function recoveryMessage(StandbyGeneration $generation, Throwable $exception): string
    {
        return (
            rtrim($exception->getMessage(), ' ')
            .' The promoted generation '
            .$generation->id
            .' is stale: the standby '
            .($this->identity->isPrimary() ? '' : "'{$this->identity->namespace}' ")
            .'was rebuilt or promoted from another checkout, so this manifest names resources the host no longer has.'
            .' Run `'
            .StaleStandbyManifest::RECOVERY_COMMAND
            .'` with the SHA main holds to rebuild it from the'
            .' base image; the standby is not corrupt and needs no manual incus delete.'
        );
    }
}
