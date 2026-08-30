<?php

declare(strict_types=1);

namespace App\Data\Metrics;

final readonly class MetricsMutationData
{
    public function __construct(
        public int $nodeId,
        public string $status,
    ) {}

    /** @return array{node_id: int, status: string} */
    public function toArray(): array
    {
        return [
            'node_id' => $this->nodeId,
            'status' => $this->status,
        ];
    }
}
