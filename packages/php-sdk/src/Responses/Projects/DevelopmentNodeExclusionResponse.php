<?php

declare(strict_types=1);

namespace Orbit\Sdk\Responses\Projects;

use Orbit\Sdk\GatewayApiException;

final readonly class DevelopmentNodeExclusionResponse
{
    public function __construct(
        public int $projectId,
        public string $projectSlug,
        public int $nodeId,
        public string $nodeName,
        public int $developmentInstanceCount,
        public bool $alreadyExists,
        public string $requestId,
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromGatewayData(array $data, string $requestId): self
    {
        $projectId = $data['project_id'] ?? null;
        $nodeId = $data['node_id'] ?? null;
        $count = $data['development_instance_count'] ?? null;
        $projectSlug = $data['project_slug'] ?? null;
        $nodeName = $data['node_name'] ?? null;

        if (! is_int($projectId) || ! is_int($nodeId) || ! is_int($count) || ! is_string($projectSlug) || ! is_string($nodeName)) {
            throw new GatewayApiException('Gateway response contains an invalid development node exclusion.', requestId: $requestId);
        }

        return new self(
            projectId: $projectId,
            projectSlug: $projectSlug,
            nodeId: $nodeId,
            nodeName: $nodeName,
            developmentInstanceCount: $count,
            alreadyExists: ($data['already_exists'] ?? false) === true,
            requestId: $requestId,
        );
    }

    /**
     * @return array{
     *     project_id: int,
     *     project_slug: string,
     *     node_id: int,
     *     node_name: string,
     *     development_instance_count: int,
     *     already_exists: bool,
     *     request_id: string
     * }
     */
    public function toArray(): array
    {
        return [
            'project_id' => $this->projectId,
            'project_slug' => $this->projectSlug,
            'node_id' => $this->nodeId,
            'node_name' => $this->nodeName,
            'development_instance_count' => $this->developmentInstanceCount,
            'already_exists' => $this->alreadyExists,
            'request_id' => $this->requestId,
        ];
    }
}
