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

    /**
     * Only a disable carries a publication outcome, so the key is absent
     * rather than null on every other mutation.
     *
     * @return array{node_id: int, status: string, publication?: string}
     */
    public function toArray(): array
    {
        $data = [
            'node_id' => $this->nodeId,
            'status' => $this->status,
        ];

        if (! $this->publication instanceof MetricsPublicationCleanup) {
            return $data;
        }

        return [...$data, 'publication' => $this->publication->value];
    }
}
