<?php

declare(strict_types=1);

namespace Orbit\Sdk\Responses\GatewayReleases;

final readonly class GatewayReleasesResponse
{
    /** @param list<GatewayReleaseResponse> $releases */
    public function __construct(
        public array $releases,
        public string $requestId,
    ) {}

    /** @return array{releases: list<array<string, mixed>>, request_id: string} */
    public function toArray(): array
    {
        return [
            'releases' => array_map(
                static function (GatewayReleaseResponse $release): array {
                    $data = $release->toArray();
                    unset($data['request_id']);

                    return $data;
                },
                $this->releases,
            ),
            'request_id' => $this->requestId,
        ];
    }
}
