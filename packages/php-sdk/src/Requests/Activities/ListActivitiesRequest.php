<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\Activities;

use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Responses\Activities\ActivitiesResponse;
use Orbit\Sdk\Responses\Activities\ActivityResponse;
use Saloon\Enums\Method;
use Saloon\Http\Response;

final class ListActivitiesRequest extends GatewayRequest
{
    #[\Override]
    protected Method $method = Method::GET;

    public function __construct(
        private readonly int $limit = 25,
        private readonly ?string $requestId = null,
        private readonly ?int $beforeId = null,
        private readonly ?string $status = null,
        private readonly ?string $command = null,
        private readonly ?int $callerNodeId = null,
        private readonly ?int $targetNodeId = null,
    ) {}

    public function resolveEndpoint(): string
    {
        return '/api/v1/activities';
    }

    public function createDtoFromResponse(#[\SensitiveParameter] Response $response): ActivitiesResponse
    {
        $requestId = $this->successRequestId($response);
        $activities = [];

        foreach ($this->unwrapDataList($response) as $data) {
            $activities[] = ActivityResponse::fromGatewayData($data, $requestId);
        }

        return new ActivitiesResponse($activities, $requestId);
    }

    /** @return array<string, int|string> */
    protected function defaultQuery(): array
    {
        $query = ['limit' => $this->limit];

        if ($this->requestId !== null) {
            $query['request_id'] = $this->requestId;
        }

        if ($this->beforeId !== null) {
            $query['before_id'] = $this->beforeId;
        }

        if ($this->status !== null) {
            $query['status'] = $this->status;
        }

        if ($this->command !== null) {
            $query['command'] = $this->command;
        }

        if ($this->callerNodeId !== null) {
            $query['caller_node_id'] = $this->callerNodeId;
        }

        if ($this->targetNodeId !== null) {
            $query['target_node_id'] = $this->targetNodeId;
        }

        return $query;
    }
}
