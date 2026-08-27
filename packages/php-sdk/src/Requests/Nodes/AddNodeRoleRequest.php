<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\Nodes;

use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Responses\Nodes\NodeRoleMutationResponse;
use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Response;
use Saloon\Traits\Body\HasJsonBody;

final class AddNodeRoleRequest extends GatewayRequest implements HasBody
{
    use HasJsonBody;

    #[\Override]
    protected Method $method = Method::POST;

    public function __construct(
        private readonly int $nodeId,
        private readonly string $role,
        private readonly bool $convergeExisting = false,
    ) {}

    public function resolveEndpoint(): string
    {
        return "/api/v1/nodes/{$this->nodeId}/roles";
    }

    public function createDtoFromResponse(#[\SensitiveParameter] Response $response): NodeRoleMutationResponse
    {
        $data = $this->unwrapData($response);
        $requestId = $this->successRequestId($response);

        return NodeRoleMutationResponse::fromGatewayData($data, $requestId);
    }

    /** @return array{role: string, converge_existing: bool} */
    protected function defaultBody(): array
    {
        return [
            'role' => $this->role,
            'converge_existing' => $this->convergeExisting,
        ];
    }
}
