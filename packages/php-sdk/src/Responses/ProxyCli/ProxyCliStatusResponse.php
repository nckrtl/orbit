<?php

declare(strict_types=1);

namespace Orbit\Sdk\Responses\ProxyCli;

use Orbit\Sdk\GatewayApiException;
use SensitiveParameter;

final readonly class ProxyCliStatusResponse
{
    public function __construct(
        public bool $enabled,
        public string $hostname,
        public ?int $nodeId,
        public ?string $cacheConnection,
        public ?string $collectedAt,
        public string $requestId,
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromGatewayData(
        #[SensitiveParameter]
        array $data,
        #[SensitiveParameter]
        string $requestId,
    ): self {
        $hostname = $data['hostname'] ?? null;
        $nodeId = $data['node_id'] ?? null;
        $cacheConnection = $data['cache_connection'] ?? null;
        $collectedAt = $data['collected_at'] ?? null;

        if (
            ! is_bool($data['enabled'] ?? null)
            || ! is_string($hostname)
            || $hostname === ''
            || strlen($hostname) > 255
            || ($nodeId !== null && (! is_int($nodeId) || $nodeId < 1))
            || ($cacheConnection !== null && (! is_string($cacheConnection) || $cacheConnection === '' || strlen($cacheConnection) > 64))
            || ($collectedAt !== null && (! is_string($collectedAt) || $collectedAt === '' || strlen($collectedAt) > 64))
        ) {
            throw new GatewayApiException('Gateway response contains invalid proxycli status.', requestId: $requestId);
        }

        return new self(
            $data['enabled'],
            $hostname,
            $nodeId,
            $cacheConnection,
            $collectedAt,
            $requestId,
        );
    }

    /**
     * @return array{
     *     enabled: bool,
     *     hostname: string,
     *     node_id: int|null,
     *     cache_connection: string|null,
     *     collected_at: string|null,
     *     request_id: string
     * }
     */
    public function toArray(): array
    {
        return [
            'enabled' => $this->enabled,
            'hostname' => $this->hostname,
            'node_id' => $this->nodeId,
            'cache_connection' => $this->cacheConnection,
            'collected_at' => $this->collectedAt,
            'request_id' => $this->requestId,
        ];
    }
}
