<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\Schedules;

use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Responses\Schedules\SchedulesResponse;
use Saloon\Enums\Method;
use Saloon\Http\Response;
use SensitiveParameter;

final class ListSchedulesRequest extends GatewayRequest
{
    #[\Override]
    protected Method $method = Method::GET;

    public function resolveEndpoint(): string
    {
        return '/api/v1/schedules';
    }

    public function createDtoFromResponse(#[SensitiveParameter] Response $response): SchedulesResponse
    {
        return SchedulesResponse::fromGatewayData(
            $this->unwrapDataList($response, rejectMalformed: true),
            $this->successRequestId($response),
        );
    }
}
