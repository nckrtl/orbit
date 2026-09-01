<?php

declare(strict_types=1);

namespace App\Infrastructure\Metrics;

use App\Domain\Metrics\ExporterDegradationReason;
use App\Domain\Metrics\ExporterDegradationRepository;
use App\Domain\Metrics\MetricsExporterLifecycle;
use App\Domain\Metrics\MetricsExporterProjection;
use App\Domain\Metrics\MetricsExporterProjectionItem;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Node;
use App\Models\NodeRole;
use Closure;
use Throwable;

final readonly class NativeMetricsExporterLifecycle implements MetricsExporterLifecycle
{
    public function __construct(
        private MetricsExporterRuntime $executor,
        private MetricsExporterProjection $projection,
        private ExporterDegradationRepository $degradations,
    ) {}

    public function converge(Node $node, NodeRole $assignment): void
    {
        $this->mutateFleet($node, function (MetricsExporterProjectionItem $item) use ($node): void {
            $item->selection->selected
                ? $this->executor->converge($item->node, $node)
                : $this->executor->remove($item->node, $node);
        });
    }

    public function remove(Node $node, NodeRole $assignment): void
    {
        $this->mutateFleet(
            $node,
            fn (MetricsExporterProjectionItem $item): mixed => $this->executor->remove($item->node, $node),
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
        $targets = [];

        foreach ($this->projection->for($metricsNode) as $item) {
            if (! $item->selection->selected) {
                continue;
            }

            $node = $item->node;
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

    /** @param Closure(MetricsExporterProjectionItem): mixed $mutation */
    private function mutateFleet(Node $metricsNode, Closure $mutation): void
    {
        /** @var list<array{item: MetricsExporterProjectionItem, state: MetricsExporterState}> $snapshots */
        $snapshots = [];

        foreach ($this->projection->for($metricsNode) as $item) {
            $candidate = $item->node;

            try {
                $state = $this->executor->snapshot($candidate, $metricsNode);
            } catch (ResourceOperationException $exception) {
                $this->degrade($candidate, $metricsNode, $exception);

                continue;
            }

            $this->degradations->forget($candidate->id);
            $snapshots[] = ['item' => $item, 'state' => $state];
        }

        $mutated = [];

        try {
            foreach ($snapshots as $snapshot) {
                $mutated[] = $snapshot;
                $mutation($snapshot['item']);
            }
        } catch (Throwable $exception) {
            try {
                foreach (array_reverse($mutated) as $snapshot) {
                    $this->executor->restore($snapshot['item']->node, $metricsNode, $snapshot['state']);
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
