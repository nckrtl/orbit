<?php

declare(strict_types=1);

namespace Orbit\Sdk\Responses\Fleet;

/** One fleet rollout: the desired state and each Node's result. */
final readonly class FleetRolloutResponse
{
    /**
     * @param  array<array-key, mixed>|null  $alert
     * @param  array<array-key, mixed>  $desiredState
     * @param  list<FleetRolloutNodeResponse>  $nodes
     */
    public function __construct(
        public int $id,
        public ?string $release,
        public string $commit,
        public string $status,
        public ?string $haltedNode,
        public ?string $errorCode,
        public ?string $message,
        public ?array $alert,
        public array $desiredState,
        public array $nodes,
        public ?string $startedAt,
        public ?string $finishedAt,
    ) {}

    /** @param array<array-key, mixed> $data */
    public static function fromGatewayData(array $data, string $requestId): self
    {
        $record = 'fleet rollout';
        $desired = $data['desired_state'] ?? null;

        if (! is_array($desired)) {
            throw FleetFields::invalid($record, $requestId);
        }

        return new self(
            id: FleetFields::int($data, 'id', $record, $requestId),
            release: FleetFields::nullableString($data, 'release', $record, $requestId),
            commit: FleetFields::string($data, 'commit', $record, $requestId),
            status: FleetFields::string($data, 'status', $record, $requestId),
            haltedNode: FleetFields::nullableString($data, 'halted_node', $record, $requestId),
            errorCode: FleetFields::nullableString($data, 'error_code', $record, $requestId),
            message: FleetFields::nullableString($data, 'message', $record, $requestId),
            alert: FleetFields::nullableObject($data, 'alert', $record, $requestId),
            desiredState: $desired,
            nodes: array_map(
                static fn (array $row): FleetRolloutNodeResponse => FleetRolloutNodeResponse::fromGatewayData($row, $requestId),
                FleetFields::rows($data, 'nodes', $record, $requestId),
            ),
            startedAt: FleetFields::nullableString($data, 'started_at', $record, $requestId),
            finishedAt: FleetFields::nullableString($data, 'finished_at', $record, $requestId),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'release' => $this->release,
            'commit' => $this->commit,
            'status' => $this->status,
            'halted_node' => $this->haltedNode,
            'error_code' => $this->errorCode,
            'message' => $this->message,
            'alert' => $this->alert,
            'desired_state' => $this->desiredState,
            'nodes' => array_map(static fn (FleetRolloutNodeResponse $node): array => $node->toArray(), $this->nodes),
            'started_at' => $this->startedAt,
            'finished_at' => $this->finishedAt,
        ];
    }
}
