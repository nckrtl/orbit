<?php

declare(strict_types=1);

namespace Orbit\Sdk\Responses\Instances;

use Orbit\Sdk\GatewayApiException;
use Orbit\Sdk\Support\GatewayRequestId;
use SensitiveParameter;

final readonly class ResolvedInstanceResponse
{
    public function __construct(
        public string $domain,
        public int $instanceId,
        public int $projectId,
        public int $nodeId,
        public string $environment,
        public string $requestId,
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromGatewayData(#[SensitiveParameter] array $data, #[SensitiveParameter] string $domain, #[SensitiveParameter] string $requestId): self
    {
        if (count($data) !== 5 || ! is_string($data['domain'] ?? null)
            || $data['domain'] !== strtolower(trim($domain)) || strlen($data['domain']) > 253
            || preg_match('/\A[a-z0-9](?:[a-z0-9-]*[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]*[a-z0-9])?)+\z/D', $data['domain']) !== 1
            || ! is_int($data['instance_id'] ?? null) || $data['instance_id'] < 1
            || ! is_int($data['project_id'] ?? null) || $data['project_id'] < 1
            || ! is_int($data['node_id'] ?? null) || $data['node_id'] < 1
            || ! in_array($data['environment'] ?? null, ['development', 'production'], true)
            || GatewayRequestId::fromTransport($requestId) === null) {
            throw new GatewayApiException('Gateway response contains invalid instance resolution data.', requestId: $requestId);
        }

        return new self($data['domain'], $data['instance_id'], $data['project_id'], $data['node_id'], $data['environment'], $requestId);
    }

    /** @return array<string, int|string> */
    public function toArray(): array
    {
        return ['domain' => $this->domain, 'instance_id' => $this->instanceId, 'project_id' => $this->projectId,
            'node_id' => $this->nodeId, 'environment' => $this->environment, 'request_id' => $this->requestId];
    }
}
