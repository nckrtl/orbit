<?php

declare(strict_types=1);

namespace Orbit\Sdk\Responses\AppInstances;

use Orbit\Sdk\GatewayApiException;
use Orbit\Sdk\Support\GatewayRequestId;
use SensitiveParameter;

final readonly class ResolvedDirectoryInstanceResponse
{
    public function __construct(
        public int $instanceId,
        public int $appId,
        public int $nodeId,
        public string $environment,
        public string $requestId,
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromGatewayData(#[SensitiveParameter] array $data, #[SensitiveParameter] string $requestId): self
    {
        if (count($data) !== 4 || ! is_int($data['instance_id'] ?? null) || $data['instance_id'] < 1
            || ! is_int($data['app_id'] ?? null) || $data['app_id'] < 1
            || ! is_int($data['node_id'] ?? null) || $data['node_id'] < 1
            || ! in_array($data['environment'] ?? null, ['development', 'production'], true)
            || GatewayRequestId::fromTransport($requestId) === null) {
            throw new GatewayApiException('Gateway response contains invalid instance resolution data.', requestId: $requestId);
        }

        return new self($data['instance_id'], $data['app_id'], $data['node_id'], $data['environment'], $requestId);
    }

    /** @return array<string, int|string> */
    public function toArray(): array
    {
        return ['instance_id' => $this->instanceId, 'app_id' => $this->appId,
            'node_id' => $this->nodeId, 'environment' => $this->environment, 'request_id' => $this->requestId];
    }
}
