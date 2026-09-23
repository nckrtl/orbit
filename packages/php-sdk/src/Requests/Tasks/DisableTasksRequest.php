<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\Tasks;

use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Responses\Tasks\TasksStatusResponse;
use Saloon\Enums\Method;
use Saloon\Http\Response;

final class DisableTasksRequest extends GatewayRequest
{
    #[\Override]
    protected Method $method = Method::POST;

    public function resolveEndpoint(): string
    {
        return '/api/v1/tasks/disable';
    }

    public function createDtoFromResponse(#[\SensitiveParameter] Response $response): TasksStatusResponse
    {
        return TasksStatusResponse::fromGatewayData($this->unwrapData($response), $this->successRequestId($response));
    }
}
