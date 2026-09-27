<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\Tasks;

use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Responses\Tasks\TaskGroupResponse;
use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Response;
use Saloon\Traits\Body\HasStringBody;

final class CreateTaskGroupRequest extends GatewayRequest implements HasBody
{
    use HasStringBody;

    #[\Override]
    protected Method $method = Method::POST;

    /** @param list<SubtaskInput>|null $tasks */
    public function __construct(
        private readonly int $appId,
        private readonly string $title,
        private readonly string $brief,
        private readonly ?string $status = null,
        private readonly ?bool $notifyCoder = null,
        private readonly ?array $tasks = null,
    ) {}

    public function resolveEndpoint(): string
    {
        return '/api/v1/task-groups';
    }

    /** @return array<string, string> */
    protected function defaultHeaders(): array
    {
        return ['Content-Type' => 'application/json'];
    }

    public function createDtoFromResponse(#[\SensitiveParameter] Response $response): TaskGroupResponse
    {
        return TaskGroupResponse::fromGatewayData($this->unwrapData($response), $this->successRequestId($response));
    }

    protected function defaultBody(): string
    {
        $body = array_filter(
            [
                'app_id' => $this->appId,
                'title' => $this->title,
                'brief' => $this->brief,
                'status' => $this->status,
                'notify_coder' => $this->notifyCoder,
                'tasks' => $this->tasks === null ? null : array_map(static fn (SubtaskInput $task): array => $task->toArray(), $this->tasks),
            ],
            static fn (mixed $value): bool => $value !== null,
        );

        return json_encode($body, JSON_THROW_ON_ERROR);
    }
}
