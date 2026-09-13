<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\Schedules;

use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Responses\Schedules\ScheduleLogsResponse;
use Saloon\Enums\Method;
use Saloon\Http\Response;
use SensitiveParameter;

final class ScheduleLogsRequest extends GatewayRequest
{
    #[\Override]
    protected Method $method = Method::GET;

    public function __construct(
        #[SensitiveParameter]
        private readonly string $scheduleId,
        private readonly ?int $lines = null,
    ) {}

    public function resolveEndpoint(): string
    {
        return '/api/v1/schedules/'.rawurlencode($this->scheduleId).'/logs';
    }

    public function createDtoFromResponse(#[SensitiveParameter] Response $response): ScheduleLogsResponse
    {
        return ScheduleLogsResponse::fromGatewayData(
            $this->unwrapData($response),
            $this->successRequestId($response),
        );
    }

    /** @return array{lines?: int} */
    protected function defaultQuery(): array
    {
        return $this->lines === null ? [] : ['lines' => $this->lines];
    }
}
