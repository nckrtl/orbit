<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\Processes;

use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Responses\Processes\ProcessResponse;
use Saloon\Enums\Method;
use Saloon\Http\Response;

final class RemoveProcessRequest extends GatewayRequest
{
    #[\Override]
    protected Method $method = Method::DELETE;

    public function __construct(
        private readonly int $processId,
    ) {}

    public function resolveEndpoint(): string
    {
        return "/api/v1/processes/{$this->processId}";
    }

    public function createDtoFromResponse(#[\SensitiveParameter] Response $response): ProcessResponse
    {
        $requestId = $this->successRequestId($response);

        return ProcessResponse::fromGatewayData($this->unwrapData($response), $requestId);
    }
}
