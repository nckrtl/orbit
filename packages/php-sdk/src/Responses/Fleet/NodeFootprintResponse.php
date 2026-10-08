<?php

declare(strict_types=1);

namespace Orbit\Sdk\Responses\Fleet;

/** What `node:converge` re-applied on a Node. */
final readonly class NodeFootprintResponse
{
    /** @param array<string, string> $artifacts  Artifact name to `applied` or `unchanged`. */
    public function __construct(
        public int $nodeId,
        public string $node,
        public array $artifacts,
        public string $digest,
        public bool $changed,
        public string $requestId,
    ) {}

    /** @param array<array-key, mixed> $data */
    public static function fromGatewayData(array $data, string $requestId): self
    {
        $record = 'Node footprint';
        $artifacts = $data['artifacts'] ?? null;
        $changed = $data['changed'] ?? null;

        if (! is_array($artifacts) || ! is_bool($changed)) {
            throw FleetFields::invalid($record, $requestId);
        }

        $named = [];

        foreach ($artifacts as $name => $outcome) {
            if (! is_string($name) || ! is_string($outcome)) {
                throw FleetFields::invalid($record, $requestId);
            }

            $named[$name] = $outcome;
        }

        return new self(
            nodeId: FleetFields::int($data, 'node_id', $record, $requestId),
            node: FleetFields::string($data, 'node', $record, $requestId),
            artifacts: $named,
            digest: FleetFields::string($data, 'digest', $record, $requestId),
            changed: $changed,
            requestId: $requestId,
        );
    }

    /** @return array{node_id: int, node: string, artifacts: array<string, string>, digest: string, changed: bool, request_id: string} */
    public function toArray(): array
    {
        return [
            'node_id' => $this->nodeId,
            'node' => $this->node,
            'artifacts' => $this->artifacts,
            'digest' => $this->digest,
            'changed' => $this->changed,
            'request_id' => $this->requestId,
        ];
    }
}
