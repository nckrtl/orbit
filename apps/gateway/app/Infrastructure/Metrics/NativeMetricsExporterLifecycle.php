<?php

declare(strict_types=1);

namespace App\Infrastructure\Metrics;

use App\Domain\Metrics\ExporterDegradationReason;
use App\Domain\Metrics\ExporterDegradationRepository;
use App\Domain\Metrics\MetricsExporterLifecycle;
use App\Domain\Metrics\MetricsExporterProjection;
use App\Domain\Metrics\MetricsExporterProjectionItem;
use App\Domain\Metrics\MetricsFleetReconcileException;
use App\Domain\Metrics\MetricsReconcileComponent;
use App\Domain\Metrics\MetricsResourceFailure;
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

    /**
     * Snapshots each candidate before mutation and skips candidates that cannot take part.
     * A candidate skipped here is never mutated, preserving rollback over the remaining fleet.
     *
     * @param  Closure(MetricsExporterProjectionItem): mixed  $mutation
     */
    private function mutateFleet(Node $metricsNode, Closure $mutation): void
    {
        /** @var list<array{item: MetricsExporterProjectionItem, state: MetricsExporterState}> $snapshots */
        $snapshots = [];

        foreach ($this->projection->for($metricsNode) as $item) {
            $candidate = $item->node;

            try {
                $state = $this->executor->snapshot($candidate, $metricsNode);
            } catch (Throwable $exception) {
                if (! $exception instanceof ResourceOperationException) {
                    throw $this->failure($candidate, $exception);
                }

                try {
                    $this->degrade($candidate, $metricsNode, $exception);
                } catch (Throwable $failure) {
                    throw $this->failure($candidate, $failure);
                }

                continue;
            }

            $this->degradations->forget($candidate->id);
            $snapshots[] = ['item' => $item, 'state' => $state];
        }

        $mutated = [];
        $failedNode = $metricsNode;

        try {
            foreach ($snapshots as $snapshot) {
                $failedNode = $snapshot['item']->node;
                $mutated[] = $snapshot;
                $mutation($snapshot['item']);
            }
        } catch (Throwable $exception) {
            $mutationFailedNode = $failedNode;
            $rollbackFailedNode = null;

            try {
                foreach (array_reverse($mutated) as $snapshot) {
                    $rollbackFailedNode = $snapshot['item']->node;
                    $this->executor->restore($rollbackFailedNode, $metricsNode, $snapshot['state']);
                }
            } catch (Throwable $rollback) {
                throw $this->failure(
                    $rollbackFailedNode ?? $mutationFailedNode,
                    new ResourceOperationException(
                        'metrics.exporter_fleet_rollback_failed',
                        'Metrics exporter fleet state could not be restored.',
                        502,
                        new ResourceOperationException(
                            'metrics.exporter_fleet_convergence_failed',
                            $exception->getMessage(),
                            502,
                            $rollback,
                        ),
                    ),
                );
            }

            throw $this->failure($mutationFailedNode, $exception);
        }
    }

    private function failure(Node $node, Throwable $exception): MetricsFleetReconcileException
    {
        $structured = MetricsResourceFailure::find($exception);

        return new MetricsFleetReconcileException(
            MetricsReconcileComponent::Exporter,
            $node->id,
            $structured->errorCode ?? 'metrics.exporter_reconcile_failed',
            $structured === null ? 'Metrics exporter reconciliation failed.' : $structured->getMessage(),
            $structured->status ?? 502,
            $exception,
            $structured->details ?? [],
        );
    }

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
