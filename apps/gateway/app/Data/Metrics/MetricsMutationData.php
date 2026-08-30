<?php

declare(strict_types=1);

namespace App\Data\Metrics;

use App\Domain\Metrics\MetricsPublicationCleanup;

final readonly class MetricsMutationData
{
    public function __construct(
        public int $nodeId,
        public string $status,
        public ?MetricsPublicationCleanup $publication = null,
    ) {}

    /** @return array{node_id: int, status: string, publication: ?string} */
    public function toArray(): array
    {
        return [
            'node_id' => $this->nodeId,
            'status' => $this->status,
            'publication' => $this->publication?->value,
        ];
    }
}
