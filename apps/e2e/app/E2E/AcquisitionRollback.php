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
 * @mago-expect lint:cyclomatic-complexity Every target is checked before any mutation.
 */
final readonly class AcquisitionRollback
{
    /**
     * @param Closure(string): (IncusInstance|IncusNetwork|null) $read
     * @param Closure(string): void $stop
     * @param Closure(string): void $deleteInstance
     * @param Closure(string): void $deleteNetwork
     */
    public function __construct(
        private Closure $read,
        private Closure $stop,
        private Closure $deleteInstance,
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
        $preflight = [];
        foreach ($resources as $resource) {
            try {
                $current = $this->current($resource);
                if ($current === null) {
                    $results[$resource] = 'absent';
                    continue;
                }
                $this->assertIdentity($target, $resource, $current, $this->expected($observed, $resource), $operation);
                $preflight[$resource] = true;
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

        foreach (array_reverse($resources) as $resource) {
            if (($results[$resource] ?? null) === 'absent') {
                continue;
            }
            try {
                $current = $this->current($resource);
                if ($current === null) {
                    $results[$resource] = 'absent';
                    continue;
                }
                $this->assertIdentity($target, $resource, $current, $this->expected($observed, $resource), $operation);
                if ($resource === $target->network()) {
                    ($this->deleteNetwork)($resource);
                } else {
                    if ($current instanceof IncusInstance && $current->isRunning()) {
                        ($this->stop)($resource);
                    }
                    ($this->deleteInstance)($resource);
                }
                $results[$resource] = 'removed';
            } catch (Throwable $exception) {
                $results[$resource] = 'failed:'.$exception->getMessage();
            }
        }

        return $results;
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

    private function current(string $resource): IncusInstance|IncusNetwork|null
    {
        $current = ($this->read)($resource);
        if ($current === null) {
            return null;
        }
        if (! $current instanceof IncusInstance && ! $current instanceof IncusNetwork) {
            throw new RuntimeException('Rollback resource read returned an invalid resource.');
        }

        return $current;
    }

    /** @param array<array-key, mixed>|null $expected */
    private function assertIdentity(
        TopologyTarget $target,
        string $resource,
        IncusInstance|IncusNetwork $current,
        ?array $expected,
        OperationId $operation,
    ): void {
        if (
            $expected !== $this->identity($current)
            || $current->name !== $resource
            || ($current->metadata['user.orbit.e2e.owner'] ?? null) !== 'orbit-e2e'
            || ($current->metadata['user.orbit.e2e.issue'] ?? null) !== $target->issue
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
            'metadata' => $resource->metadata,
        ];
    }
}
