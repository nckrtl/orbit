<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\Apps;

use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Responses\Apps\AppResponse;
use Saloon\Enums\Method;
use Saloon\Http\Response;

final class RemoveAppRequest extends GatewayRequest
{
    #[\Override]
    protected Method $method = Method::DELETE;

    public function __construct(
        private readonly int $appId,
    ) {}

    public function resolveEndpoint(): string
    {
        return "/api/v1/apps/{$this->appId}";
    }

    public function createDtoFromResponse(#[\SensitiveParameter] Response $response): AppResponse
    {
        $data = $this->unwrapData($response);
        $requestId = $this->successRequestId($response);

        return AppResponse::fromGatewayData($data, $requestId);
    }
}
