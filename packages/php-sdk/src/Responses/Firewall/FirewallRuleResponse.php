<?php

declare(strict_types=1);

namespace Orbit\Sdk\Responses\Firewall;

use Orbit\Sdk\Support\GatewayErrorCode;
use SensitiveParameter;

/**
 * @mago-expect lint:cyclomatic-complexity Gateway scalar fields are validated independently.
 * @mago-expect lint:excessive-parameter-list
 */
final readonly class FirewallRuleResponse
{
    public function __construct(
        public int $id,
        public int $nodeId,
        public string $node,
        public string $name,
        public string $action,
        public string $source,
        public string $protocol,
        public string $port,
        public string $status,
        public ?string $backendStatus,
        public ?string $failedStep,
        public ?string $errorCode,
        public string $requestId,
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromGatewayData(
        #[SensitiveParameter]
        array $data,
        #[SensitiveParameter]
        string $requestId,
    ): self {
        return new self(
            id: is_int($data['id'] ?? null) ? $data['id'] : 0,
            nodeId: is_int($data['node_id'] ?? null) ? $data['node_id'] : 0,
            node: is_string($data['node'] ?? null) ? $data['node'] : '',
            name: is_string($data['name'] ?? null) ? $data['name'] : '',
            action: is_string($data['action'] ?? null) ? $data['action'] : '',
            source: is_string($data['source'] ?? null) ? $data['source'] : '',
            protocol: is_string($data['protocol'] ?? null) ? $data['protocol'] : '',
            port: is_string($data['port'] ?? null) ? $data['port'] : '',
            status: is_string($data['status'] ?? null) ? $data['status'] : '',
            backendStatus: is_string($data['backend_status'] ?? null) ? $data['backend_status'] : null,
            failedStep: is_string($data['failed_step'] ?? null) ? $data['failed_step'] : null,
            errorCode: GatewayErrorCode::fromTransport($data['error_code'] ?? null),
            requestId: $requestId,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'node_id' => $this->nodeId,
            'node' => $this->node,
            'name' => $this->name,
            'action' => $this->action,
            'source' => $this->source,
            'protocol' => $this->protocol,
            'port' => $this->port,
            'status' => $this->status,
            'backend_status' => $this->backendStatus,
            'failed_step' => $this->failedStep,
            'error_code' => $this->errorCode,
            'request_id' => $this->requestId,
        ];
    }
}
