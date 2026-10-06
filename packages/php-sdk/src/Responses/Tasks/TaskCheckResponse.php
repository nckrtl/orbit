<?php

declare(strict_types=1);

namespace Orbit\Sdk\Responses\Tasks;

final readonly class TaskCheckResponse
{
    /**
     * @param  list<string>  $changedPaths
     * @param  array<array-key, mixed>|null  $deliverableEvidence
     * @param  array<array-key, mixed>|null  $receipt
     */
    public function __construct(
        public int $id,
        public string $kind,
        public string $status,
        public string $startedAt,
        public ?string $finishedAt,
        public ?int $exitCode,
        public array $changedPaths,
        public ?string $failedStep,
        public ?string $output,
        public ?array $deliverableEvidence,
        public ?string $outputTail,
        public ?array $receipt,
        public string $requestId,
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromGatewayData(array $data, string $requestId): self
    {
        return new self(
            id: TaskFields::id($data, 'id', 'task check', $requestId),
            kind: TaskFields::text($data, 'kind', 'task check', $requestId),
            status: TaskFields::text($data, 'status', 'task check', $requestId),
            startedAt: TaskFields::text($data, 'started_at', 'task check', $requestId),
            finishedAt: TaskFields::nullableText($data, 'finished_at'),
            exitCode: TaskFields::nullableInt($data, 'exit_code'),
            changedPaths: is_array($data['changed_paths'] ?? null) ? array_values(array_filter($data['changed_paths'], is_string(...))) : [],
            failedStep: TaskFields::nullableText($data, 'failed_step'),
            output: TaskFields::nullableText($data, 'output'),
            deliverableEvidence: is_array($data['deliverable_evidence'] ?? null) ? $data['deliverable_evidence'] : null,
            outputTail: TaskFields::nullableText($data, 'output_tail'),
            receipt: is_array($data['receipt'] ?? null) ? $data['receipt'] : null,
            requestId: $requestId,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id, 'kind' => $this->kind, 'status' => $this->status,
            'started_at' => $this->startedAt, 'finished_at' => $this->finishedAt, 'exit_code' => $this->exitCode,
            'changed_paths' => $this->changedPaths, 'failed_step' => $this->failedStep, 'output' => $this->output,
            'deliverable_evidence' => $this->deliverableEvidence, 'output_tail' => $this->outputTail,
            'receipt' => $this->receipt, 'request_id' => $this->requestId,
        ];
    }
}
