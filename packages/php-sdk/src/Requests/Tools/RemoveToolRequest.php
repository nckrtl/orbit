<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\Tools;

use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Responses\Tools\ToolResponse;
use Saloon\Enums\Method;
use Saloon\Http\Response;

final class RemoveToolRequest extends GatewayRequest
{
    #[\Override]
    protected Method $method = Method::DELETE;

    public function __construct(
        private readonly int $toolId,
    ) {}

    public function resolveEndpoint(): string
    {
        return "/api/v1/tools/{$this->toolId}";
    }

    public function createDtoFromResponse(#[\SensitiveParameter] Response $response): ToolResponse
    {
        return ToolResponse::fromGatewayData($this->unwrapData($response), $this->successRequestId($response));
    }
}
