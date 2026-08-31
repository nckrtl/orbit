<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\Clusters;

use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Responses\Clusters\ClusterResponse;
use Saloon\Enums\Method;
use Saloon\Http\Response;

final class RemoveClusterRequest extends GatewayRequest
{
    #[\Override]
    protected Method $method = Method::DELETE;

    public function __construct(
        private readonly int $clusterId,
    ) {}

    public function resolveEndpoint(): string
    {
        return "/api/v1/clusters/{$this->clusterId}";
    }

    public function createDtoFromResponse(#[\SensitiveParameter] Response $response): ClusterResponse
    {
        return ClusterResponse::fromGatewayData($this->unwrapData($response), $this->successRequestId($response));
    }
}
