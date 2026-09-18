<?php

declare(strict_types=1);

namespace App\Actions\Metrics;

use App\Data\Metrics\FleetNodeMetricsData;
use App\Domain\Metrics\MetricsExporterProjection;
use App\Domain\Nodes\Metrics\NodeFleetMetricsReader;
use App\Domain\Nodes\NodeAccessAuthorizer;
use App\Models\Node;

final readonly class ListFleetNodeMetricsAction
{
    public function __construct(
        private NodeFleetMetricsReader $reader,
        private MetricsExporterProjection $projection,
        private NodeAccessAuthorizer $access,
    ) {}

    /** @return list<FleetNodeMetricsData> */
    public function execute(Node $consumer): array
    {
        $result = $this->reader->read();
        $accessibleIds = array_flip($this->access->accessibleNodeIds($consumer));

        $entries = [];

        foreach ($this->projection->for($result->metricsNode) as $item) {
            $node = $item->node;

            if (! array_key_exists($node->id, $accessibleIds)) {
                continue;
            }

            $raw = $result->snapshots[$node->name] ?? null;

            $entries[] = $raw !== null
                ? FleetNodeMetricsData::available($node->id, $node->name, $raw)
                : FleetNodeMetricsData::unavailable(
                    $node->id,
                    $node->name,
                    $item->selection->selected ? 'no_samples' : 'no_exporter',
                );
        }

        usort($entries, static fn (FleetNodeMetricsData $left, FleetNodeMetricsData $right): int => strcmp(
            $left->nodeName,
            $right->nodeName,
        ));

        return $entries;
    }
}
