<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\Logs;

use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Responses\Logs\LogStreamRenewalResponse;
use Saloon\Enums\Method;
use Saloon\Http\Response;

final class RenewLogStreamRequest extends GatewayRequest
{
    #[\Override]
    protected Method $method = Method::PUT;

    public function __construct(
        private readonly InstanceLogStreamTarget|ProcessLogStreamTarget $target,
        private readonly string $streamId,
    ) {}

    public function resolveEndpoint(): string
    {
        return $this->target->logStreamsPath().'/'.rawurlencode($this->streamId);
    }

    public function createDtoFromResponse(#[\SensitiveParameter] Response $response): LogStreamRenewalResponse
    {
        return LogStreamRenewalResponse::fromGatewayData(
            $this->unwrapData($response),
            $this->successRequestId($response),
        );
    }
}
