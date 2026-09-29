<?php

declare(strict_types=1);

namespace Orbit\Sdk\Responses\Deployments;

final readonly class InstanceDeploymentsResponse
{
    /** @param list<InstanceDeploymentResponse> $deployments */
    public function __construct(
        public array $deployments,
        public string $requestId,
    ) {}

    /** @return array{deployments: list<array<string, mixed>>, request_id: string} */
    public function toArray(): array
    {
        return [
            'deployments' => array_map(
                static fn (InstanceDeploymentResponse $deployment): array => $deployment->toArray(),
                $this->deployments,
            ),
            'request_id' => $this->requestId,
        ];
    }
}
