<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\Tasks;

use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Responses\Tasks\TaskCommentResponse;
use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Response;
use Saloon\Traits\Body\HasStringBody;

final class CreateTaskCommentRequest extends GatewayRequest implements HasBody
{
    use HasStringBody;

    #[\Override]
    protected Method $method = Method::POST;

    public function __construct(
        private readonly int $groupId,
        private readonly int $taskId,
        private readonly string $type,
        private readonly string $commentBody,
        private readonly string $author,
        private readonly ?int $agentThreadId = null,
    ) {}

    public function resolveEndpoint(): string
    {
        return "/api/v1/task-groups/{$this->groupId}/tasks/{$this->taskId}/comments";
    }

    /** @return array<string, string> */
    protected function defaultHeaders(): array
    {
        return ['Content-Type' => 'application/json'];
    }

    public function createDtoFromResponse(#[\SensitiveParameter] Response $response): TaskCommentResponse
    {
        return TaskCommentResponse::fromGatewayData($this->unwrapData($response), $this->successRequestId($response));
    }

    protected function defaultBody(): string
    {
        $body = array_filter(
            [
                'type' => $this->type,
                'body' => $this->commentBody,
                'author' => $this->author,
                'agent_thread_id' => $this->agentThreadId,
            ],
            static fn (mixed $value): bool => $value !== null,
        );

        return json_encode($body, JSON_THROW_ON_ERROR);
    }
}
