<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\Nodes;

use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Responses\Nodes\RemovedNodeResponse;
use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Response;
use Saloon\Traits\Body\HasJsonBody;

final class RemoveNodeRequest extends GatewayRequest implements HasBody
{
    use HasJsonBody;

    #[\Override]
    protected Method $method = Method::DELETE;

    public function __construct(
        private readonly int $nodeId,
        private readonly bool $offline = false,
    ) {}

    public function resolveEndpoint(): string
    {
        return "/api/v1/nodes/{$this->nodeId}";
    }

    public function createDtoFromResponse(#[\SensitiveParameter] Response $response): RemovedNodeResponse
    {
        $data = $this->unwrapData($response);
        $requestId = $this->successRequestId($response);

        return RemovedNodeResponse::fromGatewayData($data, $requestId);
    }

    /** @return array{offline: bool} */
    protected function defaultBody(): array
    {
        return [
            'offline' => $this->offline,
        ];
    }
}
