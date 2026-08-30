<?php

declare(strict_types=1);

namespace Orbit\Sdk\Responses\Nodes;

final readonly class NodesResponse
{
    /** @param list<NodeResponse> $nodes */
    public function __construct(
        public array $nodes,
        public string $requestId,
    ) {}

    /**
     * @return array{nodes: list<array<string, int|string|list<string>|null|array{
     *     can_access: list<array{id: int, name: string}>,
     *     accessible_by: list<array{id: int, name: string}>
     * }|array{instance: array{path: string|null}|null, worktree: array{path: string|null}|null}>>, request_id: string}
     */
    public function toArray(): array
    {
        return [
            'nodes' => array_map(
                static function (NodeResponse $node): array {
                    $data = $node->toArray();
                    unset($data['request_id']);

                    return $data;
                },
                $this->nodes,
            ),
            'request_id' => $this->requestId,
        ];
    }
}
