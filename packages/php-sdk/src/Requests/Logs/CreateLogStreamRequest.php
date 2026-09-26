<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\Logs;

use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Responses\Logs\LogStreamResponse;
use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Response;
use Saloon\Traits\Body\HasJsonBody;

final class CreateLogStreamRequest extends GatewayRequest implements HasBody
{
    use HasJsonBody;

    #[\Override]
    protected Method $method = Method::POST;

    public function __construct(
        private readonly InstanceLogStreamTarget|ProcessLogStreamTarget $target,
        private readonly string $socketId,
        private readonly int $lines = 100,
    ) {}

    public function resolveEndpoint(): string
    {
        return $this->target->logStreamsPath();
    }

    public function createDtoFromResponse(#[\SensitiveParameter] Response $response): LogStreamResponse
    {
        return LogStreamResponse::fromGatewayData(
            $this->unwrapData($response),
            $this->successRequestId($response),
        );
    }

    /** @return array{socket_id: string, lines: int} */
    protected function defaultBody(): array
    {
        return ['socket_id' => $this->socketId, 'lines' => $this->lines];
    }
}
