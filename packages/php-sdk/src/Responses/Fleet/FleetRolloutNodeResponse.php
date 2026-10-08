<?php

declare(strict_types=1);

namespace Orbit\Sdk\Responses\Fleet;

/** One Node of a fleet rollout. */
final readonly class FleetRolloutNodeResponse
{
    /** @param array<array-key, mixed>|null $evidence */
    public function __construct(
        public ?int $nodeId,
        public string $node,
        public int $position,
        public string $outcome,
        public ?string $step,
        public ?string $errorCode,
        public ?string $message,
        public ?array $evidence,
        public ?string $cliVersion,
        public ?string $agentVersion,
        public ?string $footprintDigest,
        public int $attempts,
        public ?string $startedAt,
        public ?string $finishedAt,
    ) {}

    /** @param array<array-key, mixed> $data */
    public static function fromGatewayData(array $data, string $requestId): self
    {
        $record = 'fleet rollout node';
        $nodeId = $data['node_id'] ?? null;

        if ($nodeId !== null && ! is_int($nodeId)) {
            throw FleetFields::invalid($record, $requestId);
        }

        return new self(
            nodeId: $nodeId,
            node: FleetFields::string($data, 'node', $record, $requestId),
            position: FleetFields::int($data, 'position', $record, $requestId),
            outcome: FleetFields::string($data, 'outcome', $record, $requestId),
            step: FleetFields::nullableString($data, 'step', $record, $requestId),
            errorCode: FleetFields::nullableString($data, 'error_code', $record, $requestId),
            message: FleetFields::nullableString($data, 'message', $record, $requestId),
            evidence: FleetFields::nullableObject($data, 'evidence', $record, $requestId),
            cliVersion: FleetFields::nullableString($data, 'cli_version', $record, $requestId),
            agentVersion: FleetFields::nullableString($data, 'agent_version', $record, $requestId),
            footprintDigest: FleetFields::nullableString($data, 'footprint_digest', $record, $requestId),
            attempts: FleetFields::int($data, 'attempts', $record, $requestId),
            startedAt: FleetFields::nullableString($data, 'started_at', $record, $requestId),
            finishedAt: FleetFields::nullableString($data, 'finished_at', $record, $requestId),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'node_id' => $this->nodeId,
            'node' => $this->node,
            'position' => $this->position,
            'outcome' => $this->outcome,
            'step' => $this->step,
            'error_code' => $this->errorCode,
            'message' => $this->message,
            'evidence' => $this->evidence,
            'cli_version' => $this->cliVersion,
            'agent_version' => $this->agentVersion,
            'footprint_digest' => $this->footprintDigest,
            'attempts' => $this->attempts,
            'started_at' => $this->startedAt,
            'finished_at' => $this->finishedAt,
        ];
    }
}
