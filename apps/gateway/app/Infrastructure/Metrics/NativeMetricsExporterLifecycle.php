<?php

declare(strict_types=1);

namespace App\Infrastructure\Metrics;

use App\Domain\Metrics\ExporterDegradationReason;
use App\Domain\Metrics\ExporterDegradationRepository;
use App\Domain\Metrics\ExporterPreferenceRepository;
use App\Domain\Metrics\ExporterSelector;
use App\Domain\Metrics\MetricsExporterLifecycle;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Node;
use App\Models\NodeRole;
use Closure;
use Throwable;

final readonly class NativeMetricsExporterLifecycle implements MetricsExporterLifecycle
{
    public function __construct(
        private MetricsExporterRuntime $executor,
        private ExporterSelector $selector,
        private ExporterPreferenceRepository $preferences,
        private ExporterDegradationRepository $degradations,
    ) {}

    public function converge(Node $node, NodeRole $assignment): void
    {
        $node->loadMissing('roles');
        $this->mutateFleet($node, function (Node $candidate) use ($node): void {
            $this->selected($candidate, $node)
                ? $this->executor->converge($candidate, $node)
                : $this->executor->remove($candidate, $node);
        });
    }

    public function remove(Node $node, NodeRole $assignment): void
    {
        $this->mutateFleet(
            $node,
            fn (Node $candidate): mixed => $this->executor->remove($candidate, $node),
        );
    }

    public function removeNode(Node $node, Node $metricsNode): void
    {
        try {
            $this->executor->remove($node, $metricsNode);
        } finally {
            // The node is leaving the fleet either way, so it must not keep a
            // degradation record that outlives it.
            $this->degradations->forget($node->id);
        }
    }

    public function actual(Node $node): string
    {
        $assignments = NodeRole::query()
            ->where('role', RoleName::Metrics->value)
            ->with('node')
            ->limit(2)
            ->get();

        if ($assignments->count() !== 1) {
            return $assignments->isEmpty() ? 'inactive' : 'drift';
        }

        return $this->executor->actual($node, $assignments->sole()->node);
    }

    public function targets(Node $metricsNode): array
    {
        $metricsNode->loadMissing('roles');
        $targets = [];

        foreach ($this->activeNodes() as $node) {
            if (! $this->selected($node, $metricsNode)) {
                continue;
            }

            $address = $node->wireguard_ip;

            if (! is_string($address) || filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
                throw new ResourceOperationException(
                    'metrics.exporter_address_invalid',
                    "Selected Metrics exporter node [{$node->name}] requires a valid WireGuard address.",
                    409,
                );
            }

            $targets[] = ['name' => $node->name, 'address' => $address];
        }

        usort($targets, static fn (array $left, array $right): int => strcmp($left['name'], $right['name']));

        return $targets;
    }

    /** @return list<Node> */
    private function activeNodes(): array
    {
        return array_values(
            Node::query()
                ->with('roles')
                ->where('status', LifecycleStatus::Active->value)
                ->orderBy('id')
                ->get()
                ->all(),
        );
    }

    private function selected(Node $node, Node $metricsNode): bool
    {
        $roles = array_values(
            $node
                ->roles
                ->filter(static fn (NodeRole $role): bool => in_array(
                    $role->status,
                    [LifecycleStatus::Provisioning, LifecycleStatus::Active],
                    strict: true,
                ))
                ->map(static fn (NodeRole $role): RoleName => $role->role)
                ->all(),
        );

        return $this->selector->select(
            $roles,
            $this->preferences->get($node->id),
            $node->is($metricsNode),
        )->selected;
    }

    /** @param Closure(Node): mixed $mutation */
    private function mutateFleet(Node $metricsNode, Closure $mutation): void
    {
        /** @var list<array{node: Node, state: MetricsExporterState}> $snapshots */
        $snapshots = [];

        foreach ($this->activeNodes() as $candidate) {
            try {
                $state = $this->executor->snapshot($candidate, $metricsNode);
            } catch (ResourceOperationException $exception) {
                $this->degrade($candidate, $metricsNode, $exception);

                continue;
            }

            $this->degradations->forget($candidate->id);
            $snapshots[] = ['node' => $candidate, 'state' => $state];
        }

        $mutated = [];

        try {
            foreach ($snapshots as $snapshot) {
                $mutated[] = $snapshot;
                $mutation($snapshot['node']);
            }
        } catch (Throwable $exception) {
            try {
                foreach (array_reverse($mutated) as $snapshot) {
                    $this->executor->restore($snapshot['node'], $metricsNode, $snapshot['state']);
                }
            } catch (Throwable $rollback) {
                throw new ResourceOperationException(
                    'metrics.exporter_fleet_rollback_failed',
                    'Metrics exporter fleet state could not be restored.',
                    502,
                    new ResourceOperationException(
                        'metrics.exporter_fleet_convergence_failed',
                        $exception->getMessage(),
                        502,
                        $rollback,
                    ),
                );
            }

            throw $exception;
        }
    }

    /**
     * Records why one candidate is left out of this mutation, or rethrows.
     *
     * The snapshot is the only place a candidate is inspected before anything
     * is mutated, so it is the one honest point to decide that a node cannot
     * take part. A node skipped here is never mutated, which leaves the
     * all-or-nothing rollback over the remaining nodes intact.
     */
    private function degrade(Node $candidate, Node $metricsNode, ResourceOperationException $exception): void
    {
        $reason = ExporterDegradationReason::fromErrorCode($exception->errorCode);

        // The Metrics node owns the exporter projection, the Prometheus
        // targets, and the runtime. Degrading it would publish a projection
        // nobody verified, so it stays fail-closed.
        if ($reason === null || $candidate->is($metricsNode)) {
            throw $exception;
        }

        $this->degradations->put($candidate->id, $reason);
    }
}
