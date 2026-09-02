<?php

declare(strict_types=1);

namespace App\E2E;

use App\E2E\State\AtomicJsonStore;
use App\E2E\State\OperationLock;
use App\E2E\State\StatePaths;
use App\E2E\Value\OperationId;
use App\E2E\Value\TopologyProfile;
use App\E2E\Value\TopologySnapshotIdentity;
use App\E2E\Value\TopologySnapshotRecoveryContext;
use RuntimeException;

/**
 * Select the exact current or retired resource identity for explicit recovery.
 *
 * @mago-expect lint:excessive-parameter-list,cyclomatic-complexity,kan-defect The resolver assembles one exact recovery boundary.
 */
final readonly class TopologySnapshotRecoveryResolver
{
    public function __construct(
        private IncusHost $host,
        private IncusNetworkLifecycle $networks,
        private AtomicJsonStore $state,
        private StatePaths $paths,
        private OperationLock $lock,
        private OperationId $operation,
        private TopologySnapshotIdentity $identity,
    ) {}

    public function resolve(): TopologySnapshotRecoveryContext
    {
        $current = $this->identity;
        $retired = TopologySnapshotIdentity::retiredForNamespace($current->namespace);
        $retiredRecovery = $this->paths->path('standby/recovery.json');
        if (file_exists($retiredRecovery) || is_link($retiredRecovery)) {
            throw new RuntimeException(
                'A pre-rename recovery journal exists; preserve it and complete that recovery with its original code before migration.',
            );
        }
        $retained = $this->state->read('topology-snapshot/recovery.json');
        $instances = $this->host->instances([
            ...$this->instanceNames($current),
            ...$this->instanceNames($retired),
        ]);
        $networks = $this->host->networks();
        $currentPresent =
            $this->stateExists('topology-snapshot') || $this->resourcesExist($current, $instances, $networks);
        $retiredPresent = $this->stateExists('standby') || $this->resourcesExist($retired, $instances, $networks);

        if ($currentPresent && $retiredPresent) {
            throw new RuntimeException(
                'The current and retired topology snapshot identities coexist; preserve both and inspect them before recovery.',
            );
        }

        if ($retained !== null) {
            $source = $this->retainedSource($retained, $current, $retired);

            if (($retained['phase'] ?? null) === 'construction_verified') {
                if ($retiredPresent) {
                    throw new RuntimeException(
                        'Retired topology snapshot state remains after completed migration; preserve it and inspect the recovery evidence.',
                    );
                }

                $source = 'current';
            }

            return $this->context($source === 'current' ? $current : $retired, $source);
        }

        return $this->context($retiredPresent ? $retired : $current, $retiredPresent ? 'retired' : 'current');
    }

    /** @param array<array-key, mixed> $retained */
    private function retainedSource(
        array $retained,
        TopologySnapshotIdentity $current,
        TopologySnapshotIdentity $retired,
    ): string {
        $inventory = $retained['inventory'] ?? null;
        $instances = is_array($inventory) ? $inventory['instances'] ?? null : null;
        $network = is_array($inventory) ? $inventory['network'] ?? null : null;
        if (! is_array($instances)) {
            throw new RuntimeException('The retained topology snapshot recovery identity is invalid.');
        }

        $resources = [];
        foreach (array_keys($instances) as $name) {
            if (! is_string($name)) {
                throw new RuntimeException('The retained topology snapshot recovery identity is invalid.');
            }

            $resources[] = $name;
        }
        if ($network !== null) {
            if (! is_array($network) || ! is_string($network['name'] ?? null)) {
                throw new RuntimeException('The retained topology snapshot recovery identity is invalid.');
            }
            $resources[] = $network['name'];
        }
        if ($resources === []) {
            throw new RuntimeException('The retained topology snapshot recovery identity is invalid.');
        }

        $currentMatch = $this->matchesIdentity($resources, $current);
        $retiredMatch = $this->matchesIdentity($resources, $retired);
        if ($currentMatch === $retiredMatch) {
            throw new RuntimeException('The retained topology snapshot recovery identity is invalid.');
        }

        return $currentMatch ? 'current' : 'retired';
    }

    /**
     * @param list<string> $resources
     */
    private function matchesIdentity(array $resources, TopologySnapshotIdentity $identity): bool
    {
        $allowed = [...$this->instanceNames($identity), $identity->network()];

        return array_all($resources, static fn (string $resource): bool => in_array($resource, $allowed, true));
    }

    /**
     * @param array<string, mixed> $instances
     * @param array<string, mixed> $networks
     */
    private function resourcesExist(
        TopologySnapshotIdentity $identity,
        array $instances,
        array $networks,
    ): bool {
        return (
            array_any($this->instanceNames($identity), static fn (string $name): bool => isset($instances[$name]))
            || isset($networks[$identity->network()])
        );
    }

    /** @return list<string> */
    private function instanceNames(TopologySnapshotIdentity $identity): array
    {
        $names = [];
        foreach (TopologyProfile::ROLES as $role) {
            $names[] = $identity->instance($role);
            $names[] = $identity->instance($role).'-next';
        }

        return $names;
    }

    private function stateExists(string $directory): bool
    {
        foreach (['promoted.json', 'corrupt.json'] as $file) {
            $path = $this->paths->path($directory.'/'.$file);
            if (file_exists($path) || is_link($path)) {
                return true;
            }
        }

        $generations = $this->paths->path($directory.'/generations');
        if (is_link($generations)) {
            return true;
        }
        if (! is_dir($generations)) {
            return false;
        }
        $manifests = glob($generations.'/*.json');
        if ($manifests === false) {
            throw new RuntimeException('The topology snapshot generation state cannot be inspected.');
        }

        return $manifests !== [];
    }

    private function context(TopologySnapshotIdentity $identity, string $source): TopologySnapshotRecoveryContext
    {
        $stateDirectory = $source === 'current' ? 'topology-snapshot' : 'standby';
        $manifests = new TopologySnapshotManifestStore(
            $this->state,
            $this->paths,
            $this->host,
            $stateDirectory,
        );

        return new TopologySnapshotRecoveryContext(
            $source,
            new LegacyTopologySnapshotRecovery(
                $this->host,
                $manifests,
                $this->state,
                $this->operation,
                $identity,
            ),
            new TopologySnapshotRebuilder(
                $this->host,
                $this->networks,
                $manifests,
                $this->paths,
                $this->lock,
                $this->operation,
                $identity,
            ),
        );
    }
}
