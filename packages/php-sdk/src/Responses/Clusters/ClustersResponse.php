<?php

declare(strict_types=1);

namespace Orbit\Sdk\Responses\Clusters;

final readonly class ClustersResponse
{
    /** @param list<ClusterResponse> $clusters */
    public function __construct(
        public array $clusters,
        public string $requestId,
    ) {}

    /** @return array{clusters: list<array<string, mixed>>, request_id: string} */
    public function toArray(): array
    {
        return [
            'clusters' => array_map(
                static function (ClusterResponse $cluster): array {
                    $data = $cluster->toArray();
                    unset($data['request_id']);

                    return $data;
                },
                $this->clusters,
            ),
            'request_id' => $this->requestId,
        ];
    }
}
