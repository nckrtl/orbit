<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\Schedules;

use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Responses\Schedules\ScheduleResponse;
use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Response;
use Saloon\Traits\Body\HasJsonBody;
use SensitiveParameter;

final class AddScheduleRequest extends GatewayRequest implements HasBody
{
    use HasJsonBody;

    #[\Override]
    protected Method $method = Method::POST;

    public function __construct(
        private readonly NodeScheduleTarget|AppInstanceScheduleTarget $target,
        private readonly string $name,
        private readonly string $calendar,
        #[SensitiveParameter]
        private readonly string $command,
        private readonly ?int $timeoutSeconds = null,
        private readonly ?bool $start = null,
    ) {}

    public function resolveEndpoint(): string
    {
        return '/api/v1/schedules';
    }

    public function createDtoFromResponse(#[SensitiveParameter] Response $response): ScheduleResponse
    {
        return ScheduleResponse::fromGatewayData(
            $this->unwrapData($response),
            $this->successRequestId($response),
        );
    }

    /** @return array<string, bool|int|string> */
    protected function defaultBody(): array
    {
        $body = [
            ...$this->target->toRequestData(),
            'name' => $this->name,
            'calendar' => $this->calendar,
            'command' => $this->command,
        ];

        if ($this->timeoutSeconds !== null) {
            $body['timeout_seconds'] = $this->timeoutSeconds;
        }

        if ($this->start !== null) {
            $body['start'] = $this->start;
        }

        return $body;
    }
}
