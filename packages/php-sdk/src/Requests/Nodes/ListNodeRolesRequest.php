<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\Nodes;

use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Responses\Nodes\NodeRoleAssignmentResponse;
use Orbit\Sdk\Responses\Nodes\NodeRolesResponse;
use Saloon\Enums\Method;
use Saloon\Http\Response;

final class ListNodeRolesRequest extends GatewayRequest
{
    #[\Override]
    protected Method $method = Method::GET;

    public function __construct(
        private readonly int $nodeId,
    ) {}

    public function resolveEndpoint(): string
    {
        return "/api/v1/nodes/{$this->nodeId}/roles";
    }

    public function createDtoFromResponse(#[\SensitiveParameter] Response $response): NodeRolesResponse
    {
        $data = $this->unwrapDataList($response);
        $requestId = $this->successRequestId($response);
        $assignments = [];

        foreach ($data as $assignment) {
            $assignments[] = NodeRoleAssignmentResponse::fromGatewayData($assignment, $requestId);
        }

        return new NodeRolesResponse($assignments, $requestId);
    }
}
