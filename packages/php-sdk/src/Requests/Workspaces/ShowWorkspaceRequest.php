<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\Workspaces;

use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Responses\Workspaces\WorkspaceResponse;
use Saloon\Enums\Method;
use Saloon\Http\Response;

final class ShowWorkspaceRequest extends GatewayRequest
{
    protected Method $method = Method::GET;

    public function __construct(
        private readonly int $workspaceId,
    ) {}

    public function resolveEndpoint(): string
    {
        return "/api/v1/workspaces/{$this->workspaceId}";
    }

    public function createDtoFromResponse(Response $response): WorkspaceResponse
    {
        $data = $this->unwrapData($response);
        $meta = $this->unwrapMeta($response);
        $requestId = is_string($meta['request_id'] ?? null) ? $meta['request_id'] : '';

        return WorkspaceResponse::fromGatewayData($data, $requestId);
    }
}
