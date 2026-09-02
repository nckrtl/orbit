<?php

declare(strict_types=1);

namespace App\E2E;

use App\E2E\State\OperationLock;
use App\E2E\State\StatePaths;
use App\E2E\Value\IncusNetwork;
use App\E2E\Value\OperationId;
use App\E2E\Value\TopologySnapshotIdentity;
use RuntimeException;

/**
 * Remove harness-owned Incus networks that no topology uses any more.
 *
 * A network is an orphan when its name carries a harness prefix (`oe-` for the
 * current harness, `orbit-e2e-` for the legacy one), Incus reports no user, and
 * it is not a current or retired topology snapshot network. The sweep holds the host creation lock, so an
 * acquisition between network creation and its first VM is never swept.
 */
final readonly class OrphanNetworkSweep
{
    public const array HARNESS_PREFIXES = ['oe-', 'orbit-e2e-'];

    /**
     * The current and retired topology snapshot networks are never orphans.
     *
     * @return list<string>
     */
    public static function topologySnapshotNetworks(): array
    {
        return [
            TopologySnapshotIdentity::primary()->network(),
            TopologySnapshotIdentity::retired()->network(),
        ];
    }

    /** The lock every topology creation holds from network creation until its VMs exist. */
    public const string CREATION_LOCK = 'topology-create';

    public function __construct(
        private IncusHost $host,
        private IncusNetworkLifecycle $networks,
        private StatePaths $paths,
        private OperationId $operation,
    ) {}

    public static function isHarnessNetworkName(string $name): bool
    {
        return array_any(self::HARNESS_PREFIXES, fn ($prefix) => str_starts_with($name, $prefix));
    }

    /**
     * The orphan names of one network inventory, sorted.
     *
     * @param array<string, IncusNetwork> $networks
     * @param list<string> $protected Network names that are never orphans.
     * @return list<string>
     */
    public static function orphans(array $networks, array $protected = []): array
    {
        $orphans = [];
        foreach ($networks as $network) {
            $name = $network->name;
            if (
                ! self::isHarnessNetworkName($name)
                || in_array($name, self::topologySnapshotNetworks(), true)
                || $network->usedBy !== []
                || in_array($name, $protected, true)
            ) {
                continue;
            }
            $orphans[] = $name;
        }
        sort($orphans, SORT_STRING);

        return $orphans;
    }

    /**
     * Delete every orphan and return the deleted names.
     *
     * @return list<string>
     */
    public function sweep(): array
    {
        $lock = new OperationLock($this->paths);
        if (! $lock->acquire(self::CREATION_LOCK, $this->operation, timeoutSeconds: 600)) {
            throw new RuntimeException('A topology creation holds the host; the orphan sweep is skipped.');
        }
        try {
            $orphans = self::orphans($this->host->networks());
            $reaped = [];
            foreach ($orphans as $name) {
                $this->networks->deleteOrphan($name);
                $reaped[] = $name;
            }
            if ($reaped !== [] && array_intersect($reaped, array_keys($this->host->networks())) !== []) {
                throw new RuntimeException('Orphaned harness networks remain after the sweep.');
            }

            return $reaped;
        } finally {
            $lock->release();
        }
    }
}
