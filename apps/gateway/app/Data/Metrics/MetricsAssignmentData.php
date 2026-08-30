<?php

declare(strict_types=1);

namespace App\Data\Metrics;

use App\Models\NodeRole;

final readonly class MetricsAssignmentData
{
    /** @mago-expect lint:excessive-parameter-list The value preserves the complete bounded assignment projection. */
    public function __construct(
        public int $id,
        public int $nodeId,
        public string $nodeName,
        public string $status,
        public ?string $failedStep,
        public ?string $errorCode,
    ) {}

    public static function fromModel(NodeRole $assignment): self
    {
        $assignment->loadMissing('node');

        return new self(
            id: $assignment->id,
            nodeId: $assignment->node_id,
            nodeName: $assignment->node->name,
            status: $assignment->status->value,
            failedStep: $assignment->failed_step,
            errorCode: $assignment->error_code,
        );
    }

    /** @return array{id: int, node_id: int, node_name: string, status: string, failed_step: ?string, error_code: ?string} */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'node_id' => $this->nodeId,
            'node_name' => $this->nodeName,
            'status' => $this->status,
            'failed_step' => $this->failedStep,
            'error_code' => $this->errorCode,
        ];
    }
}
