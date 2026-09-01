<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\AppInstances;

use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Responses\AppInstances\AppInstanceResponse;
use Orbit\Sdk\Responses\AppInstances\AppInstancesResponse;
use Saloon\Enums\Method;
use Saloon\Http\Response;

final class ListAppInstancesRequest extends GatewayRequest
{
    #[\Override]
    protected Method $method = Method::GET;

    public function resolveEndpoint(): string
    {
        return '/api/v1/instances';
    }

    public function createDtoFromResponse(#[\SensitiveParameter] Response $response): AppInstancesResponse
    {
        $requestId = $this->successRequestId($response);
        $appInstances = [];

        foreach ($this->unwrapDataList($response) as $appInstance) {
            $appInstances[] = AppInstanceResponse::fromGatewayData($appInstance, $requestId);
        }

        return new AppInstancesResponse($appInstances, $requestId);
    }
}
