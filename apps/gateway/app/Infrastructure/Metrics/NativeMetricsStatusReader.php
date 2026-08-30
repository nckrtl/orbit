<?php

declare(strict_types=1);

namespace App\Infrastructure\Metrics;

use App\Data\Metrics\MetricsAssignmentData;
use App\Data\Metrics\MetricsExporterData;
use App\Data\Metrics\MetricsStatusData;
use App\Domain\Metrics\ExporterDegradationRepository;
use App\Domain\Metrics\ExporterPreferenceRepository;
use App\Domain\Metrics\ExporterSelector;
use App\Domain\Metrics\MetricsExporterLifecycle;
use App\Domain\Metrics\MetricsRuntimeLifecycle;
use App\Domain\Metrics\MetricsStatusReader;
use App\Domain\Nodes\RoleAssignmentException;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Models\Node;
use App\Models\NodeRole;

final readonly class NativeMetricsStatusReader implements MetricsStatusReader
{
    public function __construct(
        private ExporterSelector $selector,
        private ExporterPreferenceRepository $preferences,
        private MetricsRuntimeLifecycle $runtime,
        private MetricsExporterLifecycle $exporters,
        private ExporterDegradationRepository $degradations,
    ) {}

    public function status(): MetricsStatusData
    {
        $assignments = NodeRole::query()->where('role', RoleName::Metrics->value)->with('node')->get();
        if ($assignments->count() !== 1) {
            if ($assignments->count() > 1) {
                throw new RoleAssignmentException('Metrics role assignment drift detected.');
            }

            return new MetricsStatusData(false, null, null, 'disabled', 'disabled', []);
        }
        $assignment = $assignments->firstOrFail();
        $metrics = $assignment->node;
        $url = 'https://metrics.orbit';
        $items = [];
        foreach (Node::query()
            ->where('status', LifecycleStatus::Active->value)
            ->with('roles')
            ->orderBy('name')
            ->get() as $node) {
            // Selection counts a provisioning role the same as an active one,
            // so the exporter exists before Prometheus publishes the target.
            // Status must read the roles the same way or it reports a node as
            // excluded while its exporter is already converging.
            $roles = array_values(
                $node
                    ->roles
                    ->filter(static fn (NodeRole $role): bool => in_array(
                        $role->status,
                        [LifecycleStatus::Provisioning, LifecycleStatus::Active],
                        strict: true,
                    ))
                    ->map(static fn (NodeRole $role): RoleName => $role->role)
                    ->values()
                    ->all(),
            );
            $selection = $this->selector->select(
                $roles,
                $this->preferences->get($node->id),
                $node->id === $metrics->id,
            );
            // A node the last convergence skipped has no exporter state
            // anyone could read, so status reports the recorded reason instead
            // of waiting on a probe that is expected to fail.
            $degradation = $this->degradations->get($node->id);
            $actual = 'unknown';
            if ($degradation === null) {
                try {
                    $actual = $this->exporters->actual($node);
                } catch (\Throwable) {
                }
            }
            $items[] = new MetricsExporterData(
                $node->id,
                $node->name,
                $selection->selected,
                $actual,
                $selection->reason,
                $degradation,
            );
        }

        usort($items, static fn (MetricsExporterData $left, MetricsExporterData $right): int => strcmp(
            $left->name,
            $right->name,
        ));

        return new MetricsStatusData(
            true,
            $url,
            MetricsAssignmentData::fromModel($assignment),
            $this->runtime->health($metrics, 'prometheus') ? 'healthy' : 'unhealthy',
            $this->runtime->health($metrics, 'grafana') ? 'healthy' : 'unhealthy',
            $items,
        );
    }
}
