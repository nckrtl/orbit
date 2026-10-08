<?php

declare(strict_types=1);

namespace Orbit\Sdk\Responses\Routes;

use SensitiveParameter;

/** A Node an offline Route removal left unchanged, with the removal steps it still needs. */
final readonly class RouteRemovalResidueResponse
{
    /** @param list<string> $steps */
    public function __construct(
        public int $nodeId,
        public string $node,
        public array $steps,
    ) {}

    /** @param array<array-key, mixed> $data */
    public static function fromGatewayData(#[SensitiveParameter] array $data): self
    {
        $steps = [];

        foreach (is_array($data['steps'] ?? null) ? $data['steps'] : [] as $step) {
            if (is_string($step)) {
                $steps[] = $step;
            }
        }

        return new self(
            nodeId: is_int($data['node_id'] ?? null) ? $data['node_id'] : 0,
            node: is_string($data['node'] ?? null) ? $data['node'] : '',
            steps: $steps,
        );
    }

    /** @return array{node_id: int, node: string, steps: list<string>} */
    public function toArray(): array
    {
        return [
            'node_id' => $this->nodeId,
            'node' => $this->node,
            'steps' => $this->steps,
        ];
    }
}
