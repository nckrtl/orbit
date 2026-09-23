<?php

declare(strict_types=1);

namespace Orbit\Sdk\Responses\Projects;

final readonly class DevelopmentNodeExclusionsResponse
{
    /** @param list<DevelopmentNodeExclusionResponse> $exclusions */
    public function __construct(
        public array $exclusions,
        public string $requestId,
    ) {}

    /**
     * @return array{
     *     exclusions: list<array{
     *         project_id: int,
     *         project_slug: string,
     *         node_id: int,
     *         node_name: string,
     *         development_instance_count: int,
     *         already_exists: bool,
     *         request_id: string
     *     }>,
     *     request_id: string
     * }
     */
    public function toArray(): array
    {
        return [
            'exclusions' => array_map(
                static fn (DevelopmentNodeExclusionResponse $exclusion): array => $exclusion->toArray(),
                $this->exclusions,
            ),
            'request_id' => $this->requestId,
        ];
    }
}
