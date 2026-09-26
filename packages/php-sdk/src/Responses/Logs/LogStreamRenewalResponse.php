<?php

declare(strict_types=1);

namespace Orbit\Sdk\Responses\Logs;

use Orbit\Sdk\GatewayApiException;
use SensitiveParameter;

final readonly class LogStreamRenewalResponse
{
    public function __construct(
        public string $id,
        public int $leaseSeconds,
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
        $leaseSeconds = $data['lease_seconds'] ?? null;

        if (! LogStreamId::valid($id) || ! is_int($leaseSeconds) || $leaseSeconds < 1) {
            throw new GatewayApiException('Gateway response contains an invalid log stream renewal.', requestId: $requestId);
        }

        return new self($id, $leaseSeconds, $requestId);
    }

    /** @return array{id: string, lease_seconds: int, request_id: string} */
    public function toArray(): array
    {
        return ['id' => $this->id, 'lease_seconds' => $this->leaseSeconds, 'request_id' => $this->requestId];
    }
}
