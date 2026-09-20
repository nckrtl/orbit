<?php

declare(strict_types=1);

namespace Orbit\Sdk\Responses\Analytics;

use Orbit\Sdk\GatewayApiException;
use SensitiveParameter;

final readonly class AnalyticsCredentialsResponse
{
    public function __construct(
        public bool $configured,
        public string $driver,
        public string $requestId,
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromGatewayData(
        #[SensitiveParameter]
        array $data,
        #[SensitiveParameter]
        string $requestId,
    ): self {
        $driver = $data['driver'] ?? null;

        if (! is_bool($data['configured'] ?? null) || ! is_string($driver) || $driver === '') {
            throw new GatewayApiException(
                'Gateway response contains invalid analytics credentials data.',
                requestId: $requestId,
            );
        }

        return new self($data['configured'], $driver, $requestId);
    }

    /** @return array{configured: bool, driver: string, request_id: string} */
    public function toArray(): array
    {
        return [
            'configured' => $this->configured,
            'driver' => $this->driver,
            'request_id' => $this->requestId,
        ];
    }
}
