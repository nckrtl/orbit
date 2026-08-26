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
    #[\Override]
    protected Method $method = Method::GET;

    public function resolveEndpoint(): string
    {
        return '/api/v1/workspaces';
    }

    public function createDtoFromResponse(#[\SensitiveParameter] Response $response): WorkspacesResponse
    {
        $data = $this->unwrapDataList($response);
        $requestId = $this->successRequestId($response);
        $workspaces = [];

        foreach ($data as $workspace) {
            $workspaces[] = WorkspaceResponse::fromGatewayData($workspace, $requestId);
        }

        return new WorkspacesResponse($workspaces, $requestId);
    }
}
