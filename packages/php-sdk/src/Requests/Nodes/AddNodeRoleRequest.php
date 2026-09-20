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
        private readonly ?int $postgresProcessId = null,
        private readonly ?int $clickhouseProcessId = null,
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

    /**
     * The two Process IDs belong to the `analytics` role only, so they are absent for every other role.
     *
     * @return array{role: string, converge_existing: bool, postgres_process_id?: int, clickhouse_process_id?: int}
     */
    protected function defaultBody(): array
    {
        return [
            'role' => $this->role,
            'converge_existing' => $this->convergeExisting,
            ...($this->postgresProcessId === null ? [] : ['postgres_process_id' => $this->postgresProcessId]),
            ...($this->clickhouseProcessId === null ? [] : ['clickhouse_process_id' => $this->clickhouseProcessId]),
        ];
    }
}
