<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\Tasks;

use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Responses\Tasks\TaskGroupResponse;
use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Response;
use Saloon\Traits\Body\HasStringBody;

final class UpdateTaskGroupRequest extends GatewayRequest implements HasBody
{
    use HasStringBody;

    #[\Override]
    protected Method $method = Method::PATCH;

    public function __construct(
        private readonly int $groupId,
        private readonly ?string $title = null,
        private readonly ?string $brief = null,
        private readonly ?string $status = null,
    ) {}

    public function resolveEndpoint(): string
    {
        return "/api/v1/task-groups/{$this->groupId}";
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
                'title' => $this->title,
                'brief' => $this->brief,
                'status' => $this->status,
            ],
            static fn (mixed $value): bool => $value !== null,
        );

        return json_encode($body === [] ? new \stdClass : $body, JSON_THROW_ON_ERROR);
    }
}
