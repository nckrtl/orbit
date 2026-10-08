<?php

declare(strict_types=1);

namespace Orbit\Sdk\Responses\Routes;

use SensitiveParameter;

/** The removed Route and the Nodes an offline removal left unchanged. */
final readonly class RemovedRouteResponse
{
    /** @param list<RouteRemovalResidueResponse> $retainedOnNodes */
    public function __construct(
        public RouteResponse $route,
        public array $retainedOnNodes,
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromGatewayData(
        #[SensitiveParameter]
        array $data,
        #[SensitiveParameter]
        string $requestId,
    ): self {
        $retained = [];

        foreach (is_array($data['retained_on_nodes'] ?? null) ? $data['retained_on_nodes'] : [] as $item) {
            if (is_array($item)) {
                $retained[] = RouteRemovalResidueResponse::fromGatewayData($item);
            }
        }

        return new self(RouteResponse::fromGatewayData($data, $requestId), $retained);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $route = $this->route->toArray();
        $requestId = $route['request_id'];
        unset($route['request_id']);

        return [
            ...$route,
            'retained_on_nodes' => array_map(
                static fn (RouteRemovalResidueResponse $residue): array => $residue->toArray(),
                $this->retainedOnNodes,
            ),
            'request_id' => $requestId,
        ];
    }
}
