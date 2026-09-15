<?php

declare(strict_types=1);

namespace Orbit\Sdk\Responses\AppInstances;

use Orbit\Sdk\Support\GatewayErrorCode;
use SensitiveParameter;

final readonly class AppInstanceTransferProgressResponse
{
    /**
     * @param  array<string, mixed>|null  $recoveryEvidence
     */
    public function __construct(
        public string $operationId,
        public int $id,
        public int $sourceNodeId,
        public int $destinationNodeId,
        public string $destinationName,
        public string $destinationPath,
        public string $destinationDomain,
        public bool $sqliteSelected,
        public string $status,
        public string $currentStep,
        public bool $cutoverCompleted,
        public bool $cleanupCompleted,
        public ?string $failedStep,
        public ?string $errorCode,
        public ?array $recoveryEvidence,
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromGatewayData(#[SensitiveParameter] array $data): self
    {
        $evidence = $data['recovery_evidence'] ?? null;

        return new self(
            operationId: is_string($data['operation_id'] ?? null) ? $data['operation_id'] : '',
            id: is_int($data['id'] ?? null) ? $data['id'] : 0,
            sourceNodeId: is_int($data['source_node_id'] ?? null) ? $data['source_node_id'] : 0,
            destinationNodeId: is_int($data['destination_node_id'] ?? null) ? $data['destination_node_id'] : 0,
            destinationName: is_string($data['destination_name'] ?? null) ? $data['destination_name'] : '',
            destinationPath: is_string($data['destination_path'] ?? null) ? $data['destination_path'] : '',
            destinationDomain: is_string($data['destination_domain'] ?? null) ? $data['destination_domain'] : '',
            sqliteSelected: ($data['sqlite_selected'] ?? null) === true,
            status: is_string($data['status'] ?? null) ? $data['status'] : '',
            currentStep: is_string($data['current_step'] ?? null) ? $data['current_step'] : '',
            cutoverCompleted: ($data['cutover_completed'] ?? null) === true,
            cleanupCompleted: ($data['cleanup_completed'] ?? null) === true,
            failedStep: is_string($data['failed_step'] ?? null) ? $data['failed_step'] : null,
            errorCode: GatewayErrorCode::fromTransport($data['error_code'] ?? null),
            recoveryEvidence: is_array($evidence) ? $evidence : null,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'operation_id' => $this->operationId,
            'id' => $this->id,
            'source_node_id' => $this->sourceNodeId,
            'destination_node_id' => $this->destinationNodeId,
            'destination_name' => $this->destinationName,
            'destination_path' => $this->destinationPath,
            'destination_domain' => $this->destinationDomain,
            'sqlite_selected' => $this->sqliteSelected,
            'status' => $this->status,
            'current_step' => $this->currentStep,
            'cutover_completed' => $this->cutoverCompleted,
            'cleanup_completed' => $this->cleanupCompleted,
            'failed_step' => $this->failedStep,
            'error_code' => $this->errorCode,
            'recovery_evidence' => $this->recoveryEvidence,
        ];
    }
}
