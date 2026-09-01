<?php

declare(strict_types=1);

namespace App\Infrastructure\Metrics;

use App\Data\Metrics\MetricsAssignmentData;
use App\Data\Metrics\MetricsExporterData;
use App\Data\Metrics\MetricsStatusData;
use App\Domain\Metrics\ExporterDegradationRepository;
use App\Domain\Metrics\MetricsExporterLifecycle;
use App\Domain\Metrics\MetricsExporterProjection;
use App\Domain\Metrics\MetricsRuntimeLifecycle;
use App\Domain\Metrics\MetricsStatusReader;
use App\Domain\Nodes\RoleAssignmentException;
use App\Domain\Nodes\RoleName;
use App\Models\NodeRole;

final readonly class NativeMetricsStatusReader implements MetricsStatusReader
{
    public function __construct(
        private MetricsExporterProjection $projection,
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
        foreach ($this->projection->for($metrics) as $item) {
            $node = $item->node;
            $selection = $item->selection;
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
