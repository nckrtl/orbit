<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\Tasks;

use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Responses\Tasks\SubtaskResponse;
use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Response;
use Saloon\Traits\Body\HasStringBody;

final class CreateSubtaskRequest extends GatewayRequest implements HasBody
{
    use HasStringBody;

    #[\Override]
    protected Method $method = Method::POST;

    /** @param list<array<string, string>> $deliverables */
    public function __construct(
        private readonly int $groupId,
        private readonly string $title,
        private readonly string $brief,
        private readonly array $deliverables = [],
    ) {}

    public function resolveEndpoint(): string
    {
        return "/api/v1/task-groups/{$this->groupId}/tasks";
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
        $body = ['title' => $this->title, 'brief' => $this->brief];

        if ($this->deliverables !== []) {
            $body['deliverables'] = $this->deliverables;
        }

        return json_encode($body, JSON_THROW_ON_ERROR);
    }
}
