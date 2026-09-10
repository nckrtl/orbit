<?php

declare(strict_types=1);

namespace App\E2E;

use App\E2E\State\AtomicJsonStore;
use App\E2E\Value\AttemptId;
use App\E2E\Value\ProofResult;
use App\E2E\Value\TopologySnapshotReplacementInstallation;
use App\E2E\Value\TopologySnapshotReplacementRecovery;
use RuntimeException;

/** Host-scoped atomic journal for one clean topology snapshot replacement. */
final readonly class TopologySnapshotReplacementStore
{
    private const string ACTIVE_PATH = 'topology-snapshot/replacement.json';

    public function __construct(
        private AtomicJsonStore $store,
    ) {}

    public function active(): ?TopologySnapshotReplacementRecovery
    {
        $value = $this->store->read(self::ACTIVE_PATH);

        return $value === null ? null : TopologySnapshotReplacementRecovery::fromArray($value);
    }

    public function start(
        TopologySnapshotReplacementInstallation $installation,
        ?string $recordedAt = null,
    ): TopologySnapshotReplacementRecovery {
        $existing = $this->active();
        if ($existing !== null) {
            if (! $installation->sameIdentity($existing->installation)) {
                throw new RuntimeException('An active topology snapshot replacement has a different identity.');
            }

            return $existing;
        }
        if ($this->archived($installation->proofAttempt) !== null) {
            throw new RuntimeException('The topology snapshot replacement proof attempt is already archived.');
        }

        $recovery = TopologySnapshotReplacementRecovery::authorized(
            $installation,
            $recordedAt ?? ProofResult::now(),
        );
        $this->store->write(self::ACTIVE_PATH, $recovery->toArray());

        return $recovery;
    }

    public function advance(TopologySnapshotReplacementRecovery $recovery): void
    {
        if ($recovery->terminal()) {
            throw new RuntimeException('A terminal topology snapshot replacement must be completed.');
        }
        $this->assertCanReplace($recovery);
        $this->store->write(self::ACTIVE_PATH, $recovery->toArray());
    }

    public function complete(TopologySnapshotReplacementRecovery $recovery): void
    {
        if (! $recovery->terminal()) {
            throw new RuntimeException('A nonterminal topology snapshot replacement cannot be archived.');
        }
        $this->assertCanReplace($recovery);
        $path = $this->archivePath($recovery->installation->proofAttempt);
        $value = $recovery->toArray();
        $archived = $this->store->read($path);
        if ($archived !== null && $archived !== $value) {
            throw new RuntimeException('The topology snapshot replacement archive conflicts with retained evidence.');
        }
        if ($archived === null) {
            $this->store->write($path, $value);
        }
        if ($this->store->read($path) !== $value) {
            throw new RuntimeException('The topology snapshot replacement could not be archived.');
        }
        $this->store->delete(self::ACTIVE_PATH);
    }

    public function archived(AttemptId $proofAttempt): ?TopologySnapshotReplacementRecovery
    {
        $value = $this->store->read($this->archivePath($proofAttempt));

        return $value === null ? null : TopologySnapshotReplacementRecovery::fromArray($value);
    }

    private function assertCanReplace(TopologySnapshotReplacementRecovery $recovery): void
    {
        $existing = $this->active();
        if ($existing === null) {
            throw new RuntimeException('There is no active topology snapshot replacement.');
        }
        if (! $recovery->installation->sameIdentity($existing->installation)) {
            throw new RuntimeException('The topology snapshot replacement identity cannot change.');
        }
        if (! $recovery->canReplace($existing)) {
            throw new RuntimeException('Topology snapshot replacement progress must be monotonic.');
        }
    }

    private function archivePath(AttemptId $proofAttempt): string
    {
        return 'topology-snapshot/replacements/'.$proofAttempt->value.'.json';
    }
}
