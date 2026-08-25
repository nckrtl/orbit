<?php

declare(strict_types=1);

namespace Orbit\Sdk\Responses\Instances;

/**
 * @mago-expect lint:cyclomatic-complexity Gateway values are validated at the DTO boundary.
 * @mago-expect lint:excessive-parameter-list
 */
final readonly class InstanceResponse
{
    public function __construct(
        public int $id,
        public int $appId,
        public int $nodeId,
        public string $name,
        public string $environment,
        public string $checkoutPath,
        public string $documentRoot,
        public string $phpVersion,
        public string $hostname,
        public string $certificateMode,
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
            appId: is_int($data['app_id'] ?? null) ? $data['app_id'] : 0,
            nodeId: is_int($data['node_id'] ?? null) ? $data['node_id'] : 0,
            name: is_string($data['name'] ?? null) ? $data['name'] : '',
            environment: is_string($data['environment'] ?? null) ? $data['environment'] : '',
            checkoutPath: is_string($data['checkout_path'] ?? null) ? $data['checkout_path'] : '',
            documentRoot: is_string($data['document_root'] ?? null) ? $data['document_root'] : '',
            phpVersion: is_string($data['php_version'] ?? null) ? $data['php_version'] : '',
            hostname: is_string($data['hostname'] ?? null) ? $data['hostname'] : '',
            certificateMode: is_string($data['certificate_mode'] ?? null) ? $data['certificate_mode'] : '',
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
            'app_id' => $this->appId,
            'node_id' => $this->nodeId,
            'name' => $this->name,
            'environment' => $this->environment,
            'checkout_path' => $this->checkoutPath,
            'document_root' => $this->documentRoot,
            'php_version' => $this->phpVersion,
            'hostname' => $this->hostname,
            'certificate_mode' => $this->certificateMode,
            'status' => $this->status,
            'failed_step' => $this->failedStep,
            'error_code' => $this->errorCode,
            'request_id' => $this->requestId,
        ];
    }
}
