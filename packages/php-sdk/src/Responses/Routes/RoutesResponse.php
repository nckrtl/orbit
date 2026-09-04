<?php

declare(strict_types=1);

namespace Orbit\Sdk\Responses\Routes;

final readonly class RoutesResponse
{
    /** @param list<RouteResponse> $routes */
    public function __construct(
        public array $routes,
        public string $requestId,
    ) {}

    /** @return array{routes: list<array<string, mixed>>, request_id: string} */
    public function toArray(): array
    {
        return [
            'routes' => array_map(static function (RouteResponse $route): array {
                $data = $route->toArray();
                unset($data['request_id']);

                return $data;
            }, $this->routes),
            'request_id' => $this->requestId,
        ];
    }
}
