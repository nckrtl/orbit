<?php

declare(strict_types=1);

namespace App\Infrastructure\Metrics;

use App\Domain\Metrics\ExporterDegradationReason;
use App\Domain\Metrics\ExporterDegradationRepository;
use App\Domain\Metrics\MetricsCadvisorLifecycle;
use App\Domain\Metrics\MetricsExporterLifecycle;
use App\Domain\Metrics\MetricsFleetReconcileException;
use App\Domain\Metrics\MetricsFleetReconciler;
use App\Domain\Metrics\MetricsReconcileComponent;
use App\Domain\Metrics\MetricsReconcileDegradationRepository;
use App\Domain\Metrics\MetricsRuntimeLifecycle;
use App\Domain\Metrics\ServiceMetricsLifecycle;
use App\Domain\Nodes\RoleAssignmentException;
use App\Domain\Nodes\RoleName;
use App\Domain\Settings\SettingRepository;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
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
        private MetricsReconcileDegradationRepository $degradations = new MetricsReconcileDegradationRepository,
        private ExporterDegradationRepository $exporterDegradations = new ExporterDegradationRepository(new SettingRepository),
    ) {}

    public function reconcile(): void
    {
        $assignment = $this->activeAssignment();

        if (! $assignment instanceof NodeRole) {
            return;
        }

        $node = $assignment->node;

        try {
            $this->exporters->converge($node, $assignment);
        } catch (Throwable $exception) {
            throw $this->componentFailure(MetricsReconcileComponent::Exporter, $node->id, $exception);
        }

        try {
            $this->cadvisors->converge($node, $assignment);
        } catch (Throwable $exception) {
            throw $this->componentFailure(MetricsReconcileComponent::Cadvisor, $node->id, $exception);
        }

        try {
            if ($this->services !== null) {
                $this->services->converge($node, fn () => $this->runtime->converge($node, $assignment));
            } else {
                $this->runtime->converge($node, $assignment);
            }
        } catch (Throwable $exception) {
            throw $this->componentFailure(MetricsReconcileComponent::Runtime, $node->id, $exception);
        }

        $this->degradations->forgetAll();
        $this->exporterDegradations->forgetReconcileFailures();
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

        $this->degradations->forgetAll();
        $this->exporterDegradations->forgetReconcileFailures();
    }

    private function componentFailure(
        MetricsReconcileComponent $component,
        int $nodeId,
        Throwable $exception,
    ): MetricsFleetReconcileException {
        $failure = $exception instanceof MetricsFleetReconcileException
            ? $exception
            : null;
        $structured = null;
        for ($cause = $exception; $cause !== null; $cause = $cause->getPrevious()) {
            if ($cause instanceof ResourceOperationException) {
                $structured = $cause;
                break;
            }
        }

        $failure ??= new MetricsFleetReconcileException(
            $component,
            $nodeId,
            $structured->errorCode ?? 'metrics.'.$component->value.'_reconcile_failed',
            $structured === null
                ? 'Metrics '.$component->value.' reconciliation failed.'
                : $structured->getMessage(),
            $structured->status ?? 502,
            $exception,
            $structured->details ?? [],
        );

        if ($failure->component !== MetricsReconcileComponent::Runtime) {
            $this->exporterDegradations->put($failure->nodeId, ExporterDegradationReason::ReconcileFailed);
            $this->degradations->put($failure->nodeId, $failure->errorCode);
        }

        return $failure;
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
