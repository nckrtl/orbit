<?php

declare(strict_types=1);

namespace Orbit\Sdk\Responses\Metrics;

use Orbit\Sdk\GatewayApiException;
use SensitiveParameter;

final readonly class MetricsMutationResponse
{
    public function __construct(
        public int $nodeId,
        public string $status,
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
            || ! is_string($data['status'] ?? null)
            || ! in_array($data['status'], ['active', 'removed', 'enabled', 'disabled'], strict: true)
        ) {
            throw new GatewayApiException(
                'Gateway response contains invalid metrics mutation data.',
                requestId: $requestId,
            );
        }

        return new self($data['node_id'], $data['status'], $requestId);
    }

    /** @return array{node_id:int,status:string,request_id:string} */
    public function toArray(): array
    {
        return ['node_id' => $this->nodeId, 'status' => $this->status, 'request_id' => $this->requestId];
    }
}
