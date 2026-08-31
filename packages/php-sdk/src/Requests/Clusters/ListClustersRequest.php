<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\Clusters;

use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Responses\Clusters\ClusterResponse;
use Orbit\Sdk\Responses\Clusters\ClustersResponse;
use Saloon\Enums\Method;
use Saloon\Http\Response;

final class ListClustersRequest extends GatewayRequest
{
    #[\Override]
    protected Method $method = Method::GET;

    public function resolveEndpoint(): string
    {
        return '/api/v1/clusters';
    }

    public function createDtoFromResponse(#[\SensitiveParameter] Response $response): ClustersResponse
    {
        $data = $this->unwrapDataList($response);
        $requestId = $this->successRequestId($response);
        $clusters = [];

        foreach ($data as $cluster) {
            $clusters[] = ClusterResponse::fromGatewayData($cluster, $requestId);
        }

        return new ClustersResponse($clusters, $requestId);
    }
}
