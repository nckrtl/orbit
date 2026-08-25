<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\Instances;

use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Responses\Instances\InstanceResponse;
use Saloon\Enums\Method;
use Saloon\Http\Response;

final class RemoveInstanceRequest extends GatewayRequest
{
    protected Method $method = Method::DELETE;

    public function __construct(
        private readonly int $instanceId,
    ) {}

    public function resolveEndpoint(): string
    {
        return "/api/v1/instances/{$this->instanceId}";
    }

    public function createDtoFromResponse(Response $response): InstanceResponse
    {
        $data = $this->unwrapData($response);
        $meta = $this->unwrapMeta($response);
        $requestId = is_string($meta['request_id'] ?? null) ? $meta['request_id'] : '';

        return InstanceResponse::fromGatewayData($data, $requestId);
    }
}
