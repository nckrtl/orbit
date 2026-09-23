<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\Tasks;

use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Responses\Tasks\TaskGroupResponse;
use Saloon\Enums\Method;
use Saloon\Http\Response;

final class CancelTaskGroupRequest extends GatewayRequest
{
    #[\Override]
    protected Method $method = Method::POST;

    public function __construct(
        private readonly int $groupId,
    ) {}

    public function resolveEndpoint(): string
    {
        return "/api/v1/task-groups/{$this->groupId}/cancel";
    }

    public function createDtoFromResponse(#[\SensitiveParameter] Response $response): TaskGroupResponse
    {
        return TaskGroupResponse::fromGatewayData($this->unwrapData($response), $this->successRequestId($response));
    }
}
