<?php

declare(strict_types=1);

namespace App\Data\AppInstances;

use App\Models\AppInstanceTransfer;
use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

#[MapOutputName(SnakeCaseMapper::class)]
final class AppInstanceTransferData extends Data
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

    public static function fromModel(AppInstanceTransfer $transfer): self
    {
        return new self(
            operationId: $transfer->id,
            id: $transfer->app_instance_id,
            sourceNodeId: $transfer->source_node_id,
            destinationNodeId: $transfer->destination_node_id,
            destinationName: $transfer->destination_name,
            destinationPath: $transfer->destination_path,
            destinationDomain: $transfer->destination_domain,
            sqliteSelected: $transfer->sqlite_source_path !== null,
            status: $transfer->status->value,
            currentStep: $transfer->current_step->value,
            cutoverCompleted: $transfer->cutover_at !== null,
            cleanupCompleted: $transfer->completed_at !== null,
            failedStep: $transfer->failed_step?->value,
            errorCode: $transfer->error_code,
            recoveryEvidence: $transfer->recovery_evidence,
        );
    }
}
