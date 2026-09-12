<?php

declare(strict_types=1);

namespace Orbit\Sdk\Responses\Schedules;

use Orbit\Sdk\Support\GatewayRequestId;
use SensitiveParameter;

final readonly class ScheduleCompletionResponse
{
    private function __construct(public string $requestId) {}

    public static function fromRequestId(#[SensitiveParameter] string $requestId): self
    {
        return new self(GatewayRequestId::fromTransport($requestId) ?? '');
    }

    /** @return array{request_id: string} */
    public function toArray(): array
    {
        return ['request_id' => $this->requestId];
    }
}
