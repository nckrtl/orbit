<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\Tasks;

use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Responses\Tasks\SubtaskResponse;
use Saloon\Enums\Method;
use Saloon\Http\Response;

final class DestroySubtaskRequest extends GatewayRequest
{
    #[\Override]
    protected Method $method = Method::DELETE;

    public function __construct(
        private readonly int $groupId,
        private readonly int $taskId,
    ) {}

    public function resolveEndpoint(): string
    {
        return "/api/v1/task-groups/{$this->groupId}/tasks/{$this->taskId}";
    }

    public function createDtoFromResponse(#[\SensitiveParameter] Response $response): SubtaskResponse
    {
        return SubtaskResponse::fromGatewayData($this->unwrapData($response), $this->successRequestId($response));
    }
}
