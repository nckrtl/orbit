<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\Tasks;

use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Responses\Tasks\TaskDefinitionResponse;
use Orbit\Sdk\Responses\Tasks\TaskDefinitionsResponse;
use Saloon\Enums\Method;
use Saloon\Http\Response;

final class ListTaskDefinitionsRequest extends GatewayRequest
{
    #[\Override]
    protected Method $method = Method::GET;

    public function __construct(private readonly ?int $projectId = null) {}

    public function resolveEndpoint(): string
    {
        return '/api/v1/task-definitions';
    }

    /** @return array<string, int> */
    protected function defaultQuery(): array
    {
        return array_filter(
            ['project_id' => $this->projectId],
            static fn (?int $value): bool => $value !== null,
        );
    }

    public function createDtoFromResponse(#[\SensitiveParameter] Response $response): TaskDefinitionsResponse
    {
        $requestId = $this->successRequestId($response);
        $definitions = [];

        foreach ($this->unwrapDataList($response, true) as $entry) {
            $definitions[] = TaskDefinitionResponse::fromGatewayData($entry, $requestId);
        }

        return new TaskDefinitionsResponse($definitions, $requestId);
    }
}
