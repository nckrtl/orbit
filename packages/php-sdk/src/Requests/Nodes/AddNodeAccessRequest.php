<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\Nodes;

use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Responses\Nodes\AddedNodeAccessResponse;
use Saloon\Enums\Method;
use Saloon\Http\Response;

final class AddNodeAccessRequest extends GatewayRequest
{
    #[\Override]
    protected Method $method = Method::PUT;

    public function __construct(
        private readonly int $consumerNodeId,
        private readonly int $servingNodeId,
    ) {}

    public function resolveEndpoint(): string
    {
        return "/api/v1/nodes/{$this->servingNodeId}/access/{$this->consumerNodeId}";
    }

    public function createDtoFromResponse(#[\SensitiveParameter] Response $response): AddedNodeAccessResponse
    {
        $data = $this->unwrapData($response);
        $requestId = $this->successRequestId($response);

        return AddedNodeAccessResponse::fromGatewayData($data, $requestId);
    }
}
