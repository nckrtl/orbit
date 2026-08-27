<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\Nodes;

use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Responses\Nodes\RemovedNodeAccessResponse;
use Saloon\Enums\Method;
use Saloon\Http\Response;

final class RemoveNodeAccessRequest extends GatewayRequest
{
    #[\Override]
    protected Method $method = Method::DELETE;

    public function __construct(
        private readonly int $consumerNodeId,
        private readonly int $servingNodeId,
    ) {}

    public function resolveEndpoint(): string
    {
        return "/api/v1/nodes/{$this->servingNodeId}/access/{$this->consumerNodeId}";
    }

    public function createDtoFromResponse(#[\SensitiveParameter] Response $response): RemovedNodeAccessResponse
    {
        $data = $this->unwrapData($response);
        $requestId = $this->successRequestId($response);

        return RemovedNodeAccessResponse::fromGatewayData($data, $requestId);
    }
}
