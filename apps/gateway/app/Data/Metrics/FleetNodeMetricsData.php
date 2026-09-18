<?php

declare(strict_types=1);

namespace App\Data\Metrics;

use App\Data\Nodes\NodeMetricsData;

/**
 * One Node's row in the `metrics:node:list` fleet response: `NodeMetricsData`'s snapshot fields,
 * plus which Node it is and whether Prometheus had samples for it. An unavailable Node keeps the
 * zeroed `NodeMetricsData::fromRaw([])` shape so every row has the same fields; callers must
 * check `available` before reading them.
 */
final readonly class FleetNodeMetricsData
{
    public function __construct(
        public int $nodeId,
        public string $nodeName,
        public bool $available,
        public ?string $reason,
        public NodeMetricsData $metrics,
    ) {}

    /** @param  array<string, mixed>  $raw */
    public static function available(int $nodeId, string $nodeName, array $raw): self
    {
        return new self($nodeId, $nodeName, true, null, NodeMetricsData::fromRaw($raw));
    }

    public static function unavailable(int $nodeId, string $nodeName, string $reason): self
    {
        return new self($nodeId, $nodeName, false, $reason, NodeMetricsData::fromRaw([]));
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'node_id' => $this->nodeId,
            'node_name' => $this->nodeName,
            'available' => $this->available,
            'reason' => $this->reason,
            ...$this->metrics->toArray(),
        ];
    }
}
