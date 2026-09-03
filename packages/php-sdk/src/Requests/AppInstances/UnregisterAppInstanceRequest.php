<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\AppInstances;

use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Responses\AppInstances\AppInstanceResponse;
use Saloon\Enums\Method;
use Saloon\Http\Response;

final class UnregisterAppInstanceRequest extends GatewayRequest
{
    #[\Override]
    protected Method $method = Method::DELETE;

    public function __construct(
        private readonly int $appInstanceId,
    ) {}

    public function resolveEndpoint(): string
    {
        return "/api/v1/instances/{$this->appInstanceId}/registration";
    }

    public function createDtoFromResponse(#[\SensitiveParameter] Response $response): AppInstanceResponse
    {
        return AppInstanceResponse::fromGatewayData(
            $this->unwrapData($response),
            $this->successRequestId($response),
        );
    }
}
