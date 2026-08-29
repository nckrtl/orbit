<?php

declare(strict_types=1);

namespace App\E2E;

use App\E2E\Value\IncusInstance;
use App\E2E\Value\IncusNetwork;
use App\E2E\Value\OperationId;
use App\E2E\Value\TopologyTarget;
use Closure;
use RuntimeException;
use Throwable;

/**
 * Rolls back only resources created by one acquisition operation.
 *
 * @mago-expect lint:cyclomatic-complexity,kan-defect Every target is checked before any mutation.
 */
final readonly class AcquisitionRollback
{
    /**
     * @param Closure(list<string>): array<string, IncusInstance|IncusNetwork|null> $readBatch
     * @param Closure(list<string>): void $stopBatch
     * @param Closure(list<string>): void $deleteInstancesBatch
     * @param Closure(string): void $deleteNetwork
     */
    public function __construct(
        private Closure $readBatch,
        private Closure $stopBatch,
        private Closure $deleteInstancesBatch,
        private Closure $deleteNetwork,
    ) {}

    /** @param list<string> $resources @param array<array-key, mixed> $observed @return array<string, string> */
    public function cleanup(
        TopologyTarget $target,
        array $resources,
        array $observed,
        OperationId $operation,
    ): array {
        $results = [];
        if (count($resources) !== count(array_unique($resources))) {
            foreach ($resources as $resource) {
                $results[$resource] = 'failed:Rollback batch inventory is incomplete or contains duplicate resources.';
            }

            return $results;
        }
        /** @var array<string, IncusInstance|IncusNetwork> $preflight */
        $preflight = [];
        try {
            $inventory = ($this->readBatch)($resources);
            if (
                array_diff(array_keys($inventory), $resources) !== []
                || array_diff($resources, array_keys($inventory)) !== []
            ) {
                throw new RuntimeException('Rollback batch inventory is incomplete or contains unknown resources.');
            }
        } catch (Throwable $exception) {
            foreach ($resources as $resource) {
                $results[$resource] = 'failed:'.$exception->getMessage();
            }

            return $results;
        }
        foreach ($resources as $resource) {
            try {
                $current = $inventory[$resource];
                if ($current !== null && ! $current instanceof IncusInstance && ! $current instanceof IncusNetwork) {
                    throw new RuntimeException('Rollback resource read returned an invalid resource.');
                }
                if ($resource === $target->network() && $current !== null && ! $current instanceof IncusNetwork) {
                    throw new RuntimeException('Rollback target network is not an Incus network.');
                }
                if ($resource !== $target->network() && $current !== null && ! $current instanceof IncusInstance) {
                    throw new RuntimeException('Rollback target instance is not an Incus virtual machine.');
                }
                if ($current === null) {
                    $results[$resource] = 'absent';
                    continue;
                }
                $this->assertIdentity($target, $resource, $current, $this->expected($observed, $resource), $operation);
                $preflight[$resource] = $current;
            } catch (Throwable $exception) {
                $results[$resource] = 'refused:'.$exception->getMessage();
            }
        }
        if (
            (
                count($preflight) + count(array_filter(
                    $results,
                    static fn (string $result): bool => $result === 'absent',
                ))
            ) !== count($resources)
        ) {
            foreach (array_keys($preflight) as $resource) {
                $results[$resource] = 'retained_due_to_preflight_failure';
            }

            return $results;
        }

        $instances = array_values(array_filter(
            $resources,
            fn (string $resource): bool => (
                $resource !== $target->network()
                && ($results[$resource] ?? null) !== 'absent'
            ),
        ));
        $running = array_values(array_filter(
            $instances,
            fn (string $resource): bool => $preflight[$resource] instanceof IncusInstance
            && $preflight[$resource]->isRunning(),
        ));
        try {
            if ($running !== []) {
                ($this->stopBatch)($running);
            }
        } catch (Throwable $exception) {
            foreach ($instances as $resource) {
                $results[$resource] = in_array($resource, $running, true)
                    ? 'failed:'.$exception->getMessage()
                    : 'retained_due_to_vm_stop_failure';
            }
            $this->retainNetworkResult($target, $resources, $results, 'retained_due_to_vm_stop_failure');

            return $results;
        }
        try {
            if ($instances !== []) {
                ($this->deleteInstancesBatch)($instances);
            }
            $remaining = ($this->readBatch)($instances);
            foreach ($instances as $resource) {
                if (! array_key_exists($resource, $remaining)) {
                    throw new RuntimeException('Rollback post-delete inventory is incomplete.');
                }
                if ($remaining[$resource] !== null) {
                    $results[$resource] = 'retained:post-delete absence proof failed.';
                    throw new RuntimeException('Rollback post-delete absence proof failed for '.$resource.'.');
                }
                $results[$resource] = 'removed';
            }
        } catch (Throwable $exception) {
            foreach ($instances as $resource) {
                $results[$resource] = 'failed:'.$exception->getMessage();
            }
            $this->retainNetworkResult($target, $resources, $results, 'retained_due_to_vm_delete_failure');

            return $results;
        }
        if (in_array($target->network(), $resources, true) && ($results[$target->network()] ?? null) !== 'absent') {
            try {
                ($this->deleteNetwork)($target->network());
                $proof = ($this->readBatch)([$target->network()]);
                if (! array_key_exists($target->network(), $proof) || $proof[$target->network()] !== null) {
                    throw new RuntimeException('Rollback post-delete network absence proof failed.');
                }
                $results[$target->network()] = 'removed';
            } catch (Throwable $exception) {
                $results[$target->network()] = 'failed:'.$exception->getMessage();
            }
        }

        return $results;
    }

    /** @param list<string> $resources @param array<string, string> $results */
    private function retainNetworkResult(
        TopologyTarget $target,
        array $resources,
        array &$results,
        string $reason,
    ): void {
        $network = $target->network();
        if (in_array($network, $resources, true) && ($results[$network] ?? null) !== 'absent') {
            $results[$network] = $reason;
        }
    }

    /** @param array<array-key, mixed> $observed @return array<array-key, mixed>|null */
    private function expected(array $observed, string $resource): ?array
    {
        $expected = $observed[$resource] ?? null;
        if ($expected !== null && ! is_array($expected)) {
            throw new RuntimeException('Rollback acquisition evidence is invalid.');
        }

        return $expected;
    }

    /** @param array<array-key, mixed>|null $expected */
    private function assertIdentity(
        TopologyTarget $target,
        string $resource,
        IncusInstance|IncusNetwork $current,
        ?array $expected,
        OperationId $operation,
    ): void {
        if ($expected !== null && $current instanceof IncusInstance) {
            $expected += ['network' => null, 'mac' => null];
        }
        if ($expected !== null) {
            ksort($expected, SORT_STRING);
        }
        $identity = $this->identity($current);
        ksort($identity, SORT_STRING);
        if (
            $expected !== $identity
            || $current->name !== $resource
            || ($current->metadata['user.orbit.e2e.owner'] ?? null) !== 'orbit-e2e'
            || ($current->metadata['user.orbit.e2e.issue'] ?? null) !== $target->issue
            || ($current->metadata['user.orbit.e2e.attempt'] ?? null) !== $target->attempt?->value
            || ($current->metadata['user.orbit.e2e.operation'] ?? null) !== $operation->value
        ) {
            throw new RuntimeException('Rollback resource identity or acquisition ownership drifted.');
        }
    }

    /** @return array<string, mixed> */
    private function identity(IncusInstance|IncusNetwork $resource): array
    {
        return [
            'remote' => $resource->remote,
            'project' => $resource->project,
            'name' => $resource->name,
            'pool' => $resource instanceof IncusInstance ? $resource->pool : null,
            'network' => $resource instanceof IncusInstance ? $resource->network : null,
            'mac' => $resource instanceof IncusInstance ? $resource->mac : null,
            'metadata' => $resource->metadata,
        ];
    }
}
