<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\Tasks;

use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Responses\Tasks\TaskAgentResponse;
use Orbit\Sdk\Responses\Tasks\TaskAgentsResponse;
use Saloon\Enums\Method;
use Saloon\Http\Response;

final class ListTaskAgentsRequest extends GatewayRequest
{
    #[\Override]
    protected Method $method = Method::GET;

    public function __construct(
        private readonly int $groupId,
    ) {}

    public function resolveEndpoint(): string
    {
        return "/api/v1/task-groups/{$this->groupId}/agents";
    }

    public function createDtoFromResponse(#[\SensitiveParameter] Response $response): TaskAgentsResponse
    {
        $requestId = $this->successRequestId($response);
        $agents = [];

        foreach ($this->unwrapDataList($response, true) as $entry) {
            $agents[] = TaskAgentResponse::fromGatewayData($entry, $requestId);
        }

        return new TaskAgentsResponse($agents, $requestId);
    }
}
