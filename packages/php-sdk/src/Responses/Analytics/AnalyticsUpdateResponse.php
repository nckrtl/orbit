<?php

declare(strict_types=1);

namespace Orbit\Sdk\Responses\Analytics;

use Orbit\Sdk\GatewayApiException;
use SensitiveParameter;

final readonly class AnalyticsUpdateResponse
{
    public function __construct(
        public int $nodeId,
        public string $nodeName,
        public string $version,
        public string $previousVersion,
        public string $requestId,
    ) {}

    /** @param array<string,mixed> $data */
    public static function fromGatewayData(
        #[SensitiveParameter]
        array $data,
        #[SensitiveParameter]
        string $requestId,
    ): self {
        if (
            ! is_int($data['node_id'] ?? null)
            || $data['node_id'] < 1
            || ! is_string($data['node_name'] ?? null)
            || ! is_string($data['version'] ?? null)
            || ! is_string($data['previous_version'] ?? null)
        ) {
            throw new GatewayApiException(
                'Gateway response contains invalid analytics update data.',
                requestId: $requestId,
            );
        }

        return new self(
            $data['node_id'],
            $data['node_name'],
            $data['version'],
            $data['previous_version'],
            $requestId,
        );
    }

    /** @return array{node_id: int, node_name: string, version: string, previous_version: string, request_id: string} */
    public function toArray(): array
    {
        return [
            'node_id' => $this->nodeId,
            'node_name' => $this->nodeName,
            'version' => $this->version,
            'previous_version' => $this->previousVersion,
            'request_id' => $this->requestId,
        ];
    }
}
