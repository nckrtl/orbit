<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\Tasks;

use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Responses\Tasks\TaskDefinitionResponse;
use Saloon\Enums\Method;
use Saloon\Http\Response;

final class DestroyTaskDefinitionRequest extends GatewayRequest
{
    #[\Override]
    protected Method $method = Method::DELETE;

    public function __construct(
        private readonly int $projectId,
        private readonly string $name,
    ) {}

    public function resolveEndpoint(): string
    {
        return "/api/v1/projects/{$this->projectId}/task-definitions/".rawurlencode($this->name);
    }

    public function createDtoFromResponse(#[\SensitiveParameter] Response $response): TaskDefinitionResponse
    {
        return TaskDefinitionResponse::fromGatewayData($this->unwrapDataKeepingObjects($response), $this->successRequestId($response));
    }
}
