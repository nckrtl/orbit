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

    /** @return array{limit: int, request_id?: string} */
    protected function defaultQuery(): array
    {
        return [
            'limit' => $this->limit,
            ...($this->requestId === null ? [] : ['request_id' => $this->requestId]),
        ];
    }
}
