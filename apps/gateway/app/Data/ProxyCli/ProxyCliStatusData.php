<?php

declare(strict_types=1);

namespace App\Data\ProxyCli;

use App\Domain\ProxyCli\ProxyCliHostname;

final readonly class ProxyCliStatusData
{
    public function __construct(
        public bool $enabled,
        public ?int $nodeId,
        public ?string $cacheConnection,
        public ?string $collectedAt,
        public string $hostname = ProxyCliHostname::Value,
    ) {}

    /**
     * @return array{enabled: bool, hostname: string, node_id: int|null, cache_connection: string|null, collected_at: string|null}
     */
    public function toArray(): array
    {
        return [
            'enabled' => $this->enabled,
            'hostname' => $this->hostname,
            'node_id' => $this->nodeId,
            'cache_connection' => $this->cacheConnection,
            'collected_at' => $this->collectedAt,
        ];
    }
}
