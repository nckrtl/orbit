<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\Tasks;

use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Responses\Tasks\TaskCheckResponse;
use Saloon\Enums\Method;
use Saloon\Http\Response;

final class ShowTaskCheckRequest extends GatewayRequest
{
    #[\Override]
    protected Method $method = Method::GET;

    public function __construct(
        private readonly int $groupId,
        private readonly int $taskId,
        private readonly int $checkId,
    ) {}

    public function resolveEndpoint(): string
    {
        return "/api/v1/task-groups/{$this->groupId}/tasks/{$this->taskId}/checks/{$this->checkId}";
    }

    public function createDtoFromResponse(#[\SensitiveParameter] Response $response): TaskCheckResponse
    {
        return TaskCheckResponse::fromGatewayData($this->unwrapData($response), $this->successRequestId($response));
    }
}
