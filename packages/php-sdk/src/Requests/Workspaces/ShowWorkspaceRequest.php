<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\Workspaces;

use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Responses\Workspaces\WorkspaceResponse;
use Saloon\Enums\Method;
use Saloon\Http\Response;

final class ShowWorkspaceRequest extends GatewayRequest
{
    #[\Override]
    protected Method $method = Method::GET;

    public function __construct(
        private readonly int $workspaceId,
    ) {}

    public function resolveEndpoint(): string
    {
        return "/api/v1/workspaces/{$this->workspaceId}";
    }

    public function createDtoFromResponse(#[\SensitiveParameter] Response $response): WorkspaceResponse
    {
        $data = $this->unwrapData($response);
        $requestId = $this->successRequestId($response);

        return WorkspaceResponse::fromGatewayData($data, $requestId);
    }
}
