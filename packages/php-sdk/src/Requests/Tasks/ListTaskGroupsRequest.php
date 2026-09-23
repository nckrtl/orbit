<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\Tasks;

use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Responses\Tasks\TaskGroupResponse;
use Orbit\Sdk\Responses\Tasks\TaskGroupsResponse;
use Saloon\Enums\Method;
use Saloon\Http\Response;

final class ListTaskGroupsRequest extends GatewayRequest
{
    #[\Override]
    protected Method $method = Method::GET;

    public function __construct(
        private readonly ?int $appId = null,
        private readonly ?string $status = null,
    ) {}

    public function resolveEndpoint(): string
    {
        return '/api/v1/task-groups';
    }

    /** @return array<string, int|string> */
    protected function defaultQuery(): array
    {
        return array_filter(
            ['app_id' => $this->appId, 'status' => $this->status],
            static fn (int|string|null $value): bool => $value !== null,
        );
    }

    public function createDtoFromResponse(#[\SensitiveParameter] Response $response): TaskGroupsResponse
    {
        $requestId = $this->successRequestId($response);
        $taskGroups = [];

        foreach ($this->unwrapDataList($response, true) as $entry) {
            $taskGroups[] = TaskGroupResponse::fromGatewayData($entry, $requestId);
        }

        return new TaskGroupsResponse($taskGroups, $requestId);
    }
}
