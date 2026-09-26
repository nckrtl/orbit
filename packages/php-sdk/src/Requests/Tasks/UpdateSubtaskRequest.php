<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\Tasks;

use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Responses\Tasks\SubtaskResponse;
use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Response;
use Saloon\Traits\Body\HasStringBody;

final class UpdateSubtaskRequest extends GatewayRequest implements HasBody
{
    use HasStringBody;

    #[\Override]
    protected Method $method = Method::PATCH;

    /** @param list<array<string, string|bool>>|null $deliverables replaces the whole list; null leaves it unchanged */
    public function __construct(
        private readonly int $groupId,
        private readonly int $taskId,
        private readonly ?string $title = null,
        private readonly ?string $brief = null,
        private readonly ?int $position = null,
        private readonly ?array $deliverables = null,
    ) {}

    public function resolveEndpoint(): string
    {
        return "/api/v1/task-groups/{$this->groupId}/tasks/{$this->taskId}";
    }

    /** @return array<string, string> */
    protected function defaultHeaders(): array
    {
        return ['Content-Type' => 'application/json'];
    }

    public function createDtoFromResponse(#[\SensitiveParameter] Response $response): SubtaskResponse
    {
        return SubtaskResponse::fromGatewayData($this->unwrapData($response), $this->successRequestId($response));
    }

    protected function defaultBody(): string
    {
        $body = array_filter(
            [
                'title' => $this->title,
                'brief' => $this->brief,
                'position' => $this->position,
                'deliverables' => $this->deliverables,
            ],
            static fn (mixed $value): bool => $value !== null,
        );

        return json_encode($body === [] ? new \stdClass : $body, JSON_THROW_ON_ERROR);
    }
}
