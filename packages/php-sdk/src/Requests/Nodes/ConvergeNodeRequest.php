<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\Nodes;

use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Responses\Fleet\NodeFootprintResponse;
use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Response;
use Saloon\Traits\Body\HasJsonBody;

final class ConvergeNodeRequest extends GatewayRequest implements HasBody
{
    use HasJsonBody;

    #[\Override]
    protected Method $method = Method::POST;

    public function __construct(
        private readonly int $nodeId,
        private readonly bool $force = false,
    ) {}

    public function resolveEndpoint(): string
    {
        return "/api/v1/nodes/{$this->nodeId}/converge";
    }

    public function createDtoFromResponse(#[\SensitiveParameter] Response $response): NodeFootprintResponse
    {
        return NodeFootprintResponse::fromGatewayData($this->unwrapData($response), $this->successRequestId($response));
    }

    /** @return array{force: bool} */
    protected function defaultBody(): array
    {
        return ['force' => $this->force];
    }
}
