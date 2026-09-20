<?php

declare(strict_types=1);

namespace App\Infrastructure\Metrics;

use App\Domain\Metrics\MetricsCadvisorLifecycle;
use App\Domain\Metrics\MetricsExporterLifecycle;
use App\Domain\Metrics\MetricsFleetReconciler;
use App\Domain\Metrics\MetricsRuntimeLifecycle;
use App\Domain\Metrics\ServiceMetricsLifecycle;
use App\Domain\Nodes\RoleAssignmentException;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Models\Node;
use App\Models\NodeRole;
use Throwable;

final readonly class NativeMetricsFleetReconciler implements MetricsFleetReconciler
{
    public function __construct(
        private MetricsExporterLifecycle $exporters,
        private MetricsCadvisorLifecycle $cadvisors,
        private MetricsRuntimeLifecycle $runtime,
        private ?ServiceMetricsLifecycle $services = null,
    ) {}

    public function reconcile(): void
    {
        $assignment = $this->activeAssignment();

        if (! $assignment instanceof NodeRole) {
            return;
        }

        $node = $assignment->node;

        $this->exporters->converge($node, $assignment);
        $this->cadvisors->converge($node, $assignment);
        if ($this->services !== null) {
            $this->services->converge($node, fn () => $this->runtime->converge($node, $assignment));
        } else {
            $this->runtime->converge($node, $assignment);
        }
    }

    public function retire(Node $node): void
    {
        $assignment = $this->activeAssignment();

        if (! $assignment instanceof NodeRole) {
            return;
        }

        $metricsNode = $assignment->node;

        try {
            $this->exporters->removeNode($node, $metricsNode);
        } catch (Throwable) {
            // The node is being removed from the fleet, so its exporter state
            // is going away with it. A dead node must not hold its own removal
            // hostage; the converge below drops it from the Prometheus targets
            // regardless.
        }

        try {
            $this->cadvisors->removeNode($node, $metricsNode);
        } catch (Throwable) {
            // Same reasoning as the exporter above: best effort on the way out.
        }

        try {
            $this->services?->removeNode($node, $metricsNode);
        } catch (Throwable) {
            // Remote cleanup is best effort for a node leaving the fleet.
        }
        $this->exporters->converge($metricsNode, $assignment);
        $this->cadvisors->converge($metricsNode, $assignment);
        if ($this->services !== null) {
            $this->services->converge($metricsNode, fn () => $this->runtime->converge($metricsNode, $assignment));
        } else {
            $this->runtime->converge($metricsNode, $assignment);
        }
    }

    private function activeAssignment(): ?NodeRole
    {
        $assignments = NodeRole::query()
            ->where('role', RoleName::Metrics->value)
            ->where('status', LifecycleStatus::Active->value)
            ->whereHas('node', static fn ($query) => $query->where(
                'status',
                LifecycleStatus::Active->value,
            ))
            ->with('node')
            ->limit(2)
            ->get();

        if ($assignments->isEmpty()) {
            return null;
        }

        if ($assignments->count() !== 1) {
            throw new RoleAssignmentException('Active Metrics role assignment drift detected.');
        }

        return $assignments->firstOrFail();
    }
}
