<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\Tasks;

use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Responses\Tasks\TaskQuestionResponse;
use Orbit\Sdk\Responses\Tasks\TaskQuestionsResponse;
use Saloon\Enums\Method;
use Saloon\Http\Response;

final class ListTaskQuestionsRequest extends GatewayRequest
{
    #[\Override]
    protected Method $method = Method::GET;

    public function __construct(
        private readonly ?int $projectId = null,
        private readonly ?string $cause = null,
        private readonly ?string $status = null,
        private readonly ?string $since = null,
    ) {}

    public function resolveEndpoint(): string
    {
        return '/api/v1/task-questions';
    }

    /** @return array<string, int|string> */
    protected function defaultQuery(): array
    {
        return array_filter(
            [
                'project_id' => $this->projectId,
                'cause' => $this->cause,
                'status' => $this->status,
                'since' => $this->since,
            ],
            static fn (int|string|null $value): bool => $value !== null,
        );
    }

    public function createDtoFromResponse(#[\SensitiveParameter] Response $response): TaskQuestionsResponse
    {
        $requestId = $this->successRequestId($response);
        $questions = [];

        foreach ($this->unwrapDataList($response, true) as $entry) {
            $questions[] = TaskQuestionResponse::fromGatewayData($entry, $requestId);
        }

        return new TaskQuestionsResponse($questions, $requestId);
    }
}
