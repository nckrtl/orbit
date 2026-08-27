<?php

declare(strict_types=1);

namespace Orbit\Sdk\Responses\Nodes;

use SensitiveParameter;

final readonly class NodeAccessResponse
{
    /**
     * @param list<NodeAccessNodeResponse> $canAccess
     * @param list<NodeAccessNodeResponse> $accessibleBy
     */
    public function __construct(
        public array $canAccess,
        public array $accessibleBy,
    ) {}

    public static function fromGatewayData(
        #[SensitiveParameter]
        mixed $data,
    ): self {
        if (! is_array($data)) {
            return new self(canAccess: [], accessibleBy: []);
        }

        return new self(
            canAccess: self::nodeList($data['can_access'] ?? null),
            accessibleBy: self::nodeList($data['accessible_by'] ?? null),
        );
    }

    /**
     * @return array{
     *     can_access: list<array{id: int, name: string}>,
     *     accessible_by: list<array{id: int, name: string}>
     * }
     */
    public function toArray(): array
    {
        return [
            'can_access' => array_map(
                static fn (NodeAccessNodeResponse $node): array => $node->toArray(),
                $this->canAccess,
            ),
            'accessible_by' => array_map(
                static fn (NodeAccessNodeResponse $node): array => $node->toArray(),
                $this->accessibleBy,
            ),
        ];
    }

    /**
     * @mago-expect analysis:mixed-assignment Gateway list items remain mixed until validated.
     *
     * @return list<NodeAccessNodeResponse>
     */
    private static function nodeList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $nodes = [];

        foreach ($value as $item) {
            /** @var mixed $item */
            $node = NodeAccessNodeResponse::tryFromGatewayData($item);

            if (! $node instanceof NodeAccessNodeResponse) {
                continue;
            }

            $nodes[] = $node;
        }

        return $nodes;
    }
}
