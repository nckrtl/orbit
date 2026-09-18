<?php

declare(strict_types=1);

namespace Orbit\Sdk\Responses\Metrics;

final readonly class MetricsNodesResponse
{
    /** @param list<FleetNodeMetricsResponse> $nodes */
    public function __construct(
        public array $nodes,
        public string $requestId,
    ) {}

    /** @return array{nodes: list<array<string, mixed>>, request_id: string} */
    public function toArray(): array
    {
        return [
            'nodes' => array_map(
                static fn (FleetNodeMetricsResponse $node): array => $node->toArray(),
                $this->nodes,
            ),
            'request_id' => $this->requestId,
        ];
    }
}
