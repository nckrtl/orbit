<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\Schedules;

use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Responses\Schedules\ScheduleCompletionResponse;
use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Response;
use Saloon\Traits\Body\HasJsonBody;
use SensitiveParameter;

final class CompleteScheduleRequest extends GatewayRequest implements HasBody
{
    use HasJsonBody;

    #[\Override]
    protected Method $method = Method::POST;

    public function __construct(
        #[SensitiveParameter]
        private readonly string $scheduleId,
        private readonly string $status,
    ) {}

    public function resolveEndpoint(): string
    {
        return '/api/v1/schedules/'.rawurlencode($this->scheduleId).'/complete';
    }

    public function createDtoFromResponse(#[SensitiveParameter] Response $response): ScheduleCompletionResponse
    {
        return ScheduleCompletionResponse::fromRequestId($this->successHeaderRequestId($response));
    }

    /** @return array{status: string} */
    protected function defaultBody(): array
    {
        return ['status' => $this->status];
    }
}
