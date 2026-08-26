<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\Processes;

use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Responses\Processes\ProcessResponse;
use Saloon\Enums\Method;
use Saloon\Http\Response;

abstract class ProcessActionRequest extends GatewayRequest
{
    #[\Override]
    protected Method $method = Method::POST;

    public function __construct(
        protected readonly int $processId,
    ) {}

    final public function resolveEndpoint(): string
    {
        return "/api/v1/processes/{$this->processId}/{$this->action()}";
    }

    final public function createDtoFromResponse(#[\SensitiveParameter] Response $response): ProcessResponse
    {
        $requestId = $this->successRequestId($response);

        return ProcessResponse::fromGatewayData($this->unwrapData($response), $requestId);
    }

    abstract protected function action(): string;
}
