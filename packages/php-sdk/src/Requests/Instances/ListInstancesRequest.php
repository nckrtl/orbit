<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\Instances;

use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Responses\Instances\InstanceResponse;
use Orbit\Sdk\Responses\Instances\InstancesResponse;
use Saloon\Enums\Method;
use Saloon\Http\Response;

final class ListInstancesRequest extends GatewayRequest
{
    #[\Override]
    protected Method $method = Method::GET;

    public function resolveEndpoint(): string
    {
        return '/api/v1/instances';
    }

    public function createDtoFromResponse(#[\SensitiveParameter] Response $response): InstancesResponse
    {
        $data = $this->unwrapDataList($response);
        $requestId = $this->successRequestId($response);
        $instances = [];

        foreach ($data as $instance) {
            $instances[] = InstanceResponse::fromGatewayData($instance, $requestId);
        }

        return new InstancesResponse($instances, $requestId);
    }
}
