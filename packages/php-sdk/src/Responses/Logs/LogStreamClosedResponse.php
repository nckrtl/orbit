<?php

declare(strict_types=1);

namespace Orbit\Sdk\Responses\Logs;

use Orbit\Sdk\GatewayApiException;
use SensitiveParameter;

final readonly class LogStreamClosedResponse
{
    public function __construct(
        public string $id,
        public bool $closed,
        public string $requestId,
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromGatewayData(
        #[SensitiveParameter]
        array $data,
        #[SensitiveParameter]
        string $requestId,
    ): self {
        $id = $data['id'] ?? null;
        $closed = $data['closed'] ?? null;

        if (! LogStreamId::valid($id) || ! is_bool($closed)) {
            throw new GatewayApiException('Gateway response contains an invalid log stream closure.', requestId: $requestId);
        }

        return new self($id, $closed, $requestId);
    }

    /** @return array{id: string, closed: bool, request_id: string} */
    public function toArray(): array
    {
        return ['id' => $this->id, 'closed' => $this->closed, 'request_id' => $this->requestId];
    }
}
