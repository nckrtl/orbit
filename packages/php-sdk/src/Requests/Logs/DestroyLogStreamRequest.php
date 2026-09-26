<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\Logs;

use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Responses\Logs\LogStreamClosedResponse;
use Saloon\Enums\Method;
use Saloon\Http\Response;

final class DestroyLogStreamRequest extends GatewayRequest
{
    #[\Override]
    protected Method $method = Method::DELETE;

    public function __construct(
        private readonly InstanceLogStreamTarget|ProcessLogStreamTarget $target,
        private readonly string $streamId,
    ) {}

    public function resolveEndpoint(): string
    {
        return $this->target->logStreamsPath().'/'.rawurlencode($this->streamId);
    }

    public function createDtoFromResponse(#[\SensitiveParameter] Response $response): LogStreamClosedResponse
    {
        return LogStreamClosedResponse::fromGatewayData(
            $this->unwrapData($response),
            $this->successRequestId($response),
        );
    }
}
