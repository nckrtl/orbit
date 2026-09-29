<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\Instances;

use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Responses\Instances\InstanceResponse;
use Saloon\Enums\Method;
use Saloon\Http\Response;

final class SetupInstanceRequest extends GatewayRequest
{
    #[\Override]
    protected Method $method = Method::POST;

    public function __construct(private readonly int $instanceId) {}

    public function resolveEndpoint(): string
    {
        return "/api/v1/instances/{$this->instanceId}/setup";
    }

    public function createDtoFromResponse(#[\SensitiveParameter] Response $response): InstanceResponse
    {
        return InstanceResponse::fromGatewayData($this->unwrapData($response), $this->successRequestId($response));
    }
}
