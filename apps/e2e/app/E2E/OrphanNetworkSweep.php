<?php

declare(strict_types=1);

namespace App\E2E;

use App\E2E\State\AtomicJsonStore;
use App\E2E\State\OperationJournal;
use App\E2E\State\StatePaths;
use App\E2E\Value\AttemptId;
use App\E2E\Value\IncusNetwork;
use App\E2E\Value\NetworkSweepResult;
use App\E2E\Value\OperationId;
use App\E2E\Value\TopologyTarget;
use Closure;
use RuntimeException;
use Throwable;

/**
 * Remove harness-owned Incus networks that no topology uses any more.
 *
 * A network is an orphan when its name carries a harness prefix (`oe-` for the
 * current harness, `orbit-e2e-` for the legacy one), Incus reports no user, it
 * is not the standby network, and no active lease names it. The sweep never
 * touches a network outside those prefixes or a network with users.
 *
 * @mago-expect lint:cyclomatic-complexity Each orphan is deleted, verified, and journaled on its own so one failure never hides another deletion.
 */
final readonly class OrphanNetworkSweep
{
    public const array HARNESS_PREFIXES = ['oe-', 'orbit-e2e-'];

    public const string STANDBY_NETWORK = 'oe-standby';

    /** @mago-expect lint:excessive-parameter-list The sweep dependencies are explicit trust boundaries. */
    public function __construct(
        private IncusHost $host,
        private IncusNetworkLifecycle $networks,
        private AtomicJsonStore $state,
        private StatePaths $paths,
        private OperationJournal $journal,
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
     * @param list<string> $protected Network names that are never orphans, such as those of active leases.
     * @return list<string>
     */
    public static function orphans(array $networks, array $protected = []): array
    {
        $orphans = [];
        foreach ($networks as $network) {
            $name = $network->name;
            if (
                ! self::isHarnessNetworkName($name)
                || $name === self::STANDBY_NETWORK
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
     * Delete every orphan. Each deletion is journaled and handed to `$onDeleted`
     * as soon as it happens, so evidence survives a later failure. A network
     * whose deletion fails is reported in the result and does not stop the
     * sweep of the remaining orphans.
     *
     * @param null|Closure(string): void $onDeleted
     */
    public function sweep(?Closure $onDeleted = null): NetworkSweepResult
    {
        $orphans = self::orphans($this->host->networks(), $this->leasedNetworks());
        $reaped = [];
        $failed = [];
        foreach ($orphans as $name) {
            try {
                $this->networks->deleteOrphan($name);
            } catch (Throwable $exception) {
                $failed[$name] = $exception->getMessage();
                $this->journal->append($this->operation, [
                    'event' => 'network.sweep',
                    'state' => 'failed',
                    'network' => $name,
                    'error' => $exception->getMessage(),
                ]);
                continue;
            }
            $reaped[] = $name;
            $this->journal->append($this->operation, [
                'event' => 'network.sweep',
                'state' => 'deleted',
                'network' => $name,
            ]);
            if ($onDeleted !== null) {
                $onDeleted($name);
            }
        }
        if ($reaped !== []) {
            foreach (array_intersect($reaped, array_keys($this->host->networks())) as $name) {
                $failed[$name] = 'The network is still listed after deletion.';
                $this->journal->append($this->operation, [
                    'event' => 'network.sweep',
                    'state' => 'failed',
                    'network' => $name,
                    'error' => $failed[$name],
                ]);
            }
            $reaped = array_values(array_diff($reaped, array_keys($failed)));
        }

        return new NetworkSweepResult($reaped, $failed);
    }

    /**
     * The network of every active lease; an acquisition may own a network before
     * its first VM attaches, so a leased network is never an orphan.
     *
     * @return list<string>
     */
    private function leasedNetworks(): array
    {
        $directory = $this->paths->path('leases');
        if (! is_dir($directory)) {
            return [];
        }
        $entries = scandir($directory);
        if ($entries === false) {
            throw new RuntimeException('Unable to inspect exact topology leases.');
        }
        $names = [];
        foreach ($entries as $entry) {
            $matches = [];
            if (preg_match('/\A([A-Z][A-Z0-9]{1,9}-[1-9][0-9]{0,8})\.json\z/D', $entry, $matches) !== 1) {
                continue;
            }
            $lease = $this->state->read('leases/'.$entry);
            /** @mago-expect analysis:mixed-assignment The lease attempt is validated before use. */
            $attempt = $lease['attempt'] ?? null;
            if (! is_string($attempt) || preg_match('/\A[0-9a-f]{32}\z/D', $attempt) !== 1) {
                continue;
            }
            $names[] = TopologyTarget::feature($matches[1], new AttemptId($attempt))->network();
        }

        return $names;
    }
}
