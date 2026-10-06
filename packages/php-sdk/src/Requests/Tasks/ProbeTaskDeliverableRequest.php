<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\Tasks;

use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Responses\Tasks\TaskCheckResponse;
use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Response;
use Saloon\Traits\Body\HasStringBody;

final class ProbeTaskDeliverableRequest extends GatewayRequest implements HasBody
{
    use HasStringBody;

    #[\Override]
    protected Method $method = Method::POST;

    public function __construct(
        private readonly int $groupId,
        private readonly int $taskId,
        private readonly string $deliverableId,
        private readonly bool $base = false,
    ) {}

    public function resolveEndpoint(): string
    {
        return "/api/v1/task-groups/{$this->groupId}/tasks/{$this->taskId}/deliverables/".rawurlencode($this->deliverableId).'/probe';
    }

    /** @return array<string, string> */
    protected function defaultHeaders(): array
    {
        return ['Content-Type' => 'application/json'];
    }

    protected function defaultBody(): string
    {
        return json_encode(['base' => $this->base], JSON_THROW_ON_ERROR);
    }

    public function createDtoFromResponse(#[\SensitiveParameter] Response $response): TaskCheckResponse
    {
        return TaskCheckResponse::fromGatewayData($this->unwrapData($response), $this->successRequestId($response));
    }
}
