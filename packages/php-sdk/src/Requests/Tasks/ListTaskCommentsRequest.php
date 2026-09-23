<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\Tasks;

use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Responses\Tasks\TaskCommentResponse;
use Orbit\Sdk\Responses\Tasks\TaskCommentsResponse;
use Saloon\Enums\Method;
use Saloon\Http\Response;

final class ListTaskCommentsRequest extends GatewayRequest
{
    #[\Override]
    protected Method $method = Method::GET;

    public function __construct(
        private readonly int $groupId,
        private readonly int $taskId,
    ) {}

    public function resolveEndpoint(): string
    {
        return "/api/v1/task-groups/{$this->groupId}/tasks/{$this->taskId}/comments";
    }

    public function createDtoFromResponse(#[\SensitiveParameter] Response $response): TaskCommentsResponse
    {
        $requestId = $this->successRequestId($response);
        $comments = [];

        foreach ($this->unwrapDataList($response, true) as $entry) {
            $comments[] = TaskCommentResponse::fromGatewayData($entry, $requestId);
        }

        return new TaskCommentsResponse($comments, $requestId);
    }
}
