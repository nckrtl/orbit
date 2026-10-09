<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\Tasks;

use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Responses\Tasks\TaskQuestionResponse;
use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Response;
use Saloon\Traits\Body\HasStringBody;

/** Closes an open or escalated question as answered or superseded, with the reason as its answer. */
final class CloseTaskQuestionRequest extends GatewayRequest implements HasBody
{
    use HasStringBody;

    #[\Override]
    protected Method $method = Method::POST;

    public function __construct(
        private readonly int $questionId,
        private readonly string $status,
        private readonly string $reason,
    ) {}

    public function resolveEndpoint(): string
    {
        return "/api/v1/task-questions/{$this->questionId}/close";
    }

    /** @return array<string, string> */
    protected function defaultHeaders(): array
    {
        return ['Content-Type' => 'application/json'];
    }

    public function createDtoFromResponse(#[\SensitiveParameter] Response $response): TaskQuestionResponse
    {
        return TaskQuestionResponse::fromGatewayData($this->unwrapData($response), $this->successRequestId($response));
    }

    protected function defaultBody(): string
    {
        return json_encode(['status' => $this->status, 'reason' => $this->reason], JSON_THROW_ON_ERROR);
    }
}
