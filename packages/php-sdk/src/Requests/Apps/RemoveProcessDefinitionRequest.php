<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\Apps;

use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Responses\Apps\AppRuntimeDefinitionResponse;
use Saloon\Enums\Method;
use Saloon\Http\Response;

final class RemoveProcessDefinitionRequest extends GatewayRequest
{
    #[\Override]
    protected Method $method = Method::DELETE;

    public function __construct(
        private readonly int $appId,
        private readonly string $definitionId,
    ) {}

    public function resolveEndpoint(): string
    {
        return "/api/v1/apps/{$this->appId}/process-definitions/".rawurlencode($this->definitionId);
    }

    public function createDtoFromResponse(#[\SensitiveParameter] Response $response): AppRuntimeDefinitionResponse
    {
        return AppRuntimeDefinitionResponse::fromGatewayData(
            $this->unwrapData($response),
            $this->successRequestId($response),
        );
    }
}
