<?php

declare(strict_types=1);

namespace Orbit\Sdk\Responses\Fleet;

/** The fleet rollout as `fleet:rollout:status` and `fleet:rollout:resume` return it. */
final readonly class FleetRolloutStatusResponse
{
    /** @param list<array{node_id: int, node: string, reason: string}> $excluded */
    public function __construct(
        public bool $enabled,
        public string $status,
        public ?string $desiredCommit,
        public ?FleetRolloutResponse $rollout,
        public array $excluded,
        public string $requestId,
    ) {}

    /** @param array<array-key, mixed> $data */
    public static function fromGatewayData(array $data, string $requestId): self
    {
        $record = 'fleet rollout status';
        $enabled = $data['enabled'] ?? null;
        $rollout = FleetFields::nullableObject($data, 'rollout', $record, $requestId);

        if (! is_bool($enabled)) {
            throw FleetFields::invalid($record, $requestId);
        }

        $excluded = [];

        foreach (FleetFields::rows($data, 'excluded', $record, $requestId) as $row) {
            $excluded[] = [
                'node_id' => FleetFields::int($row, 'node_id', $record, $requestId),
                'node' => FleetFields::string($row, 'node', $record, $requestId),
                'reason' => FleetFields::string($row, 'reason', $record, $requestId),
            ];
        }

        return new self(
            enabled: $enabled,
            status: FleetFields::string($data, 'status', $record, $requestId),
            desiredCommit: FleetFields::nullableString($data, 'desired_commit', $record, $requestId),
            rollout: $rollout === null ? null : FleetRolloutResponse::fromGatewayData($rollout, $requestId),
            excluded: $excluded,
            requestId: $requestId,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'enabled' => $this->enabled,
            'status' => $this->status,
            'desired_commit' => $this->desiredCommit,
            'rollout' => $this->rollout?->toArray(),
            'excluded' => $this->excluded,
            'request_id' => $this->requestId,
        ];
    }
}
