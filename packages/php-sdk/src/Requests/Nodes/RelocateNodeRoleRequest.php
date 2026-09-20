<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\Nodes;

use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Responses\Nodes\NodeRoleMutationResponse;
use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Response;
use Saloon\Traits\Body\HasJsonBody;

final class RelocateNodeRoleRequest extends GatewayRequest implements HasBody
{
    use HasJsonBody;

    #[\Override]
    protected Method $method = Method::POST;

    public function __construct(
        private readonly int $nodeId,
        private readonly string $role,
        private readonly bool $force,
        private readonly ?int $from = null,
    ) {}

    public function resolveEndpoint(): string
    {
        return "/api/v1/nodes/{$this->nodeId}/roles/".rawurlencode($this->role).'/relocate';
    }

    public function createDtoFromResponse(#[\SensitiveParameter] Response $response): NodeRoleMutationResponse
    {
        $data = $this->unwrapData($response);
        $requestId = $this->successRequestId($response);

        return NodeRoleMutationResponse::fromGatewayData($data, $requestId);
    }

    /** @return array{force: bool, from?: int} */
    protected function defaultBody(): array
    {
        $body = [
            'force' => $this->force,
        ];

        if ($this->from !== null) {
            $body['from'] = $this->from;
        }

        return $body;
    }
}
