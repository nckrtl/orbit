<?php

declare(strict_types=1);

namespace Orbit\Sdk\Responses\Clusters;

use SensitiveParameter;

/**
 * @mago-expect lint:cyclomatic-complexity Gateway values are validated at the Cluster DTO boundary.
 * @mago-expect lint:excessive-parameter-list The constructor mirrors the complete Cluster response.
 */
final readonly class ClusterResponse
{
    /** @param list<ClusterNodeResponse> $nodes */
    public function __construct(
        public int $id,
        public string $name,
        public ?string $tld,
        public string $state,
        public array $nodes,
        public ?ClusterNodeResponse $router,
        public string $requestId,
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromGatewayData(
        #[SensitiveParameter]
        array $data,
        #[SensitiveParameter]
        string $requestId,
    ): self {
        return new self(
            id: is_int($data['id'] ?? null) ? $data['id'] : 0,
            name: is_string($data['name'] ?? null) ? $data['name'] : '',
            tld: is_string($data['tld'] ?? null) ? $data['tld'] : null,
            state: is_string($data['state'] ?? null) ? $data['state'] : '',
            nodes: self::nodes($data['nodes'] ?? null),
            router: self::node($data['router'] ?? null),
            requestId: $requestId,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'tld' => $this->tld,
            'state' => $this->state,
            'nodes' => array_map(
                static fn (ClusterNodeResponse $node): array => $node->toArray(),
                $this->nodes,
            ),
            'router' => $this->router?->toArray(),
            'request_id' => $this->requestId,
        ];
    }

    private static function node(#[SensitiveParameter] mixed $value): ?ClusterNodeResponse
    {
        if (! is_array($value)) {
            return null;
        }

        return ClusterNodeResponse::fromGatewayData(self::stringKeyed($value));
    }

    /** @return list<ClusterNodeResponse> */
    private static function nodes(#[SensitiveParameter] mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $nodes = [];

        foreach ($value as $item) {
            if (! is_array($item)) {
                continue;
            }

            $nodes[] = ClusterNodeResponse::fromGatewayData(self::stringKeyed($item));
        }

        return $nodes;
    }

    /**
     * @mago-expect analysis:mixed-assignment Gateway values remain mixed until keyed.
     *
     * @return array<string, mixed>
     */
    private static function stringKeyed(#[SensitiveParameter] array $value): array
    {
        $result = [];

        foreach ($value as $key => $item) {
            if (! is_string($key)) {
                continue;
            }

            $result[$key] = $item;
        }

        return $result;
    }
}
