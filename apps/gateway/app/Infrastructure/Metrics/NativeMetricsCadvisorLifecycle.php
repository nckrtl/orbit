<?php

declare(strict_types=1);

namespace App\Infrastructure\Metrics;

use App\Domain\Metrics\ExporterDegradationReason;
use App\Domain\Metrics\ExporterDegradationRepository;
use App\Domain\Metrics\MetricsCadvisorLifecycle;
use App\Domain\Metrics\MetricsExporterProjection;
use App\Domain\Metrics\MetricsExporterProjectionItem;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Node;
use App\Models\NodeRole;
use Closure;
use Throwable;

/**
 * Mirrors `NativeMetricsExporterLifecycle`'s fleet-wide snapshot/mutate/rollback dance, run against
 * `MetricsCadvisorRuntime` instead of the node exporter's executor. It reuses the exporter's own
 * `MetricsExporterProjection` (which Node gets a cAdvisor is exactly which Node gets a node
 * exporter) and its `ExporterDegradationRepository` (a Node degraded for the exporter is degraded
 * for cAdvisor too: both describe whether Orbit can currently manage that Node's Metrics agents).
 */
final readonly class NativeMetricsCadvisorLifecycle implements MetricsCadvisorLifecycle
{
    public function __construct(
        private MetricsCadvisorRuntime $executor,
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
            function (MetricsExporterProjectionItem $item) use ($node): void {
                $this->executor->remove($item->node, $node);
            },
        );
    }

    public function removeNode(Node $node, Node $metricsNode): void
    {
        try {
            $this->executor->remove($node, $metricsNode);
        } finally {
            $this->degradations->forget($node->id);
        }
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
                    'metrics.cadvisor_fleet_rollback_failed',
                    'cAdvisor fleet state could not be restored.',
                    502,
                    new ResourceOperationException(
                        'metrics.cadvisor_fleet_convergence_failed',
                        $exception->getMessage(),
                        502,
                        $rollback,
                    ),
                );
            }

            throw $exception;
        }
    }

    /** @see NativeMetricsExporterLifecycle::degrade() */
    private function degrade(Node $candidate, Node $metricsNode, ResourceOperationException $exception): void
    {
        $reason = ExporterDegradationReason::fromErrorCode($exception->errorCode);

        if ($reason === null || $candidate->is($metricsNode)) {
            throw $exception;
        }

        $this->degradations->put($candidate->id, $reason);
    }
}
