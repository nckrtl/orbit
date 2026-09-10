<?php

declare(strict_types=1);

namespace Orbit\Sdk\Responses\AppInstances;

use Orbit\Sdk\Support\GatewayErrorCode;
use SensitiveParameter;

final readonly class AppInstanceRemovalProgressResponse
{
    public function __construct(
        public string $operationId,
        public int $id,
        public string $name,
        public bool $force,
        public string $status,
        public ?string $currentStep,
        public int $total,
        public int $completed,
        public int $remaining,
        public ?string $failedStep,
        public ?string $errorCode,
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromGatewayData(#[SensitiveParameter] array $data): self
    {
        return new self(
            operationId: is_string($data['operation_id'] ?? null) ? $data['operation_id'] : '',
            id: is_int($data['id'] ?? null) ? $data['id'] : 0,
            name: is_string($data['name'] ?? null) ? $data['name'] : '',
            force: ($data['force'] ?? null) === true,
            status: is_string($data['status'] ?? null) ? $data['status'] : '',
            currentStep: is_string($data['current_step'] ?? null) ? $data['current_step'] : null,
            total: is_int($data['total'] ?? null) ? $data['total'] : 0,
            completed: is_int($data['completed'] ?? null) ? $data['completed'] : 0,
            remaining: is_int($data['remaining'] ?? null) ? $data['remaining'] : 0,
            failedStep: is_string($data['failed_step'] ?? null) ? $data['failed_step'] : null,
            errorCode: GatewayErrorCode::fromTransport($data['error_code'] ?? null),
        );
    }

    /** @return array<string, bool|int|string|null> */
    public function toArray(): array
    {
        return [
            'operation_id' => $this->operationId,
            'id' => $this->id,
            'name' => $this->name,
            'force' => $this->force,
            'status' => $this->status,
            'current_step' => $this->currentStep,
            'total' => $this->total,
            'completed' => $this->completed,
            'remaining' => $this->remaining,
            'failed_step' => $this->failedStep,
            'error_code' => $this->errorCode,
        ];
    }
}
