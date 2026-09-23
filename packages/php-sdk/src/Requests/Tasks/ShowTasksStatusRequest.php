<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\Tasks;

use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Responses\Tasks\TasksStatusResponse;
use Saloon\Enums\Method;
use Saloon\Http\Response;

final class ShowTasksStatusRequest extends GatewayRequest
{
    #[\Override]
    protected Method $method = Method::GET;

    public function resolveEndpoint(): string
    {
        return '/api/v1/tasks/status';
    }

    public function createDtoFromResponse(#[\SensitiveParameter] Response $response): TasksStatusResponse
    {
        return TasksStatusResponse::fromGatewayData($this->unwrapData($response), $this->successRequestId($response));
    }
}
