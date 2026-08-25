<?php

declare(strict_types=1);

namespace Orbit\Sdk\Responses\Workspaces;

/**
 * @mago-expect lint:cyclomatic-complexity Gateway values are validated at the DTO boundary.
 * @mago-expect lint:excessive-parameter-list
 */
final readonly class WorkspaceResponse
{
    public function __construct(
        public int $id,
        public int $instanceId,
        public int $nodeId,
        public string $name,
        public string $branch,
        public string $checkoutPath,
        public ?string $phpVersion,
        public string $effectivePhpVersion,
        public string $hostname,
        public string $status,
        public ?string $failedStep,
        public ?string $errorCode,
        public string $requestId,
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromGatewayData(array $data, string $requestId): self
    {
        return new self(
            id: is_int($data['id'] ?? null) ? $data['id'] : 0,
            instanceId: is_int($data['instance_id'] ?? null) ? $data['instance_id'] : 0,
            nodeId: is_int($data['node_id'] ?? null) ? $data['node_id'] : 0,
            name: is_string($data['name'] ?? null) ? $data['name'] : '',
            branch: is_string($data['branch'] ?? null) ? $data['branch'] : '',
            checkoutPath: is_string($data['checkout_path'] ?? null) ? $data['checkout_path'] : '',
            phpVersion: is_string($data['php_version'] ?? null) ? $data['php_version'] : null,
            effectivePhpVersion: is_string($data['effective_php_version'] ?? null)
                ? $data['effective_php_version']
                : '',
            hostname: is_string($data['hostname'] ?? null) ? $data['hostname'] : '',
            status: is_string($data['status'] ?? null) ? $data['status'] : '',
            failedStep: is_string($data['failed_step'] ?? null) ? $data['failed_step'] : null,
            errorCode: is_string($data['error_code'] ?? null) ? $data['error_code'] : null,
            requestId: $requestId,
        );
    }

    /** @return array<string, int|string|null> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'instance_id' => $this->instanceId,
            'node_id' => $this->nodeId,
            'name' => $this->name,
            'branch' => $this->branch,
            'checkout_path' => $this->checkoutPath,
            'php_version' => $this->phpVersion,
            'effective_php_version' => $this->effectivePhpVersion,
            'hostname' => $this->hostname,
            'status' => $this->status,
            'failed_step' => $this->failedStep,
            'error_code' => $this->errorCode,
            'request_id' => $this->requestId,
        ];
    }
}
