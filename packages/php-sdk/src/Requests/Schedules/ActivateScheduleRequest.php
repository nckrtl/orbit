<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\Schedules;

use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Responses\Schedules\ScheduleResponse;
use Saloon\Enums\Method;
use Saloon\Http\Response;
use SensitiveParameter;

final class ActivateScheduleRequest extends GatewayRequest
{
    #[\Override]
    protected Method $method = Method::POST;

    public function __construct(
        #[SensitiveParameter]
        private readonly string $scheduleId,
    ) {}

    public function resolveEndpoint(): string
    {
        return '/api/v1/schedules/'.rawurlencode($this->scheduleId).'/activate';
    }

    public function createDtoFromResponse(#[SensitiveParameter] Response $response): ScheduleResponse
    {
        return ScheduleResponse::fromGatewayData(
            $this->unwrapData($response),
            $this->successRequestId($response),
        );
    }
}
