<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\Workspaces;

use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Responses\Workspaces\WorkspaceResponse;
use Orbit\Sdk\Responses\Workspaces\WorkspacesResponse;
use Saloon\Enums\Method;
use Saloon\Http\Response;

final class ListWorkspacesRequest extends GatewayRequest
{
    protected Method $method = Method::GET;

    public function resolveEndpoint(): string
    {
        return '/api/v1/workspaces';
    }

    public function createDtoFromResponse(Response $response): WorkspacesResponse
    {
        $data = $this->unwrapDataList($response);
        $meta = $this->unwrapMeta($response);
        $requestId = is_string($meta['request_id'] ?? null) ? $meta['request_id'] : '';
        $workspaces = [];

        foreach ($data as $workspace) {
            $workspaces[] = WorkspaceResponse::fromGatewayData($workspace, $requestId);
        }

        return new WorkspacesResponse($workspaces, $requestId);
    }
}
