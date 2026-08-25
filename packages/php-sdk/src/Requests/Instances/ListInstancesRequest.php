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
    protected Method $method = Method::GET;

    public function resolveEndpoint(): string
    {
        return '/api/v1/instances';
    }

    public function createDtoFromResponse(Response $response): InstancesResponse
    {
        $data = $this->unwrapDataList($response);
        $meta = $this->unwrapMeta($response);
        $requestId = is_string($meta['request_id'] ?? null) ? $meta['request_id'] : '';
        $instances = [];

        foreach ($data as $instance) {
            $instances[] = InstanceResponse::fromGatewayData($instance, $requestId);
        }

        return new InstancesResponse($instances, $requestId);
    }
}
