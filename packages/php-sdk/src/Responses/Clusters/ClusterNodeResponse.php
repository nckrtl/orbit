<?php

declare(strict_types=1);

namespace Orbit\Sdk\Responses\Clusters;

use SensitiveParameter;

final readonly class ClusterNodeResponse
{
    public function __construct(
        public int $id,
        public string $name,
        public string $status,
        public ?string $wireguardIp,
        public ?string $lanIp,
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromGatewayData(#[SensitiveParameter] array $data): self
    {
        return new self(
            id: is_int($data['id'] ?? null) ? $data['id'] : 0,
            name: is_string($data['name'] ?? null) ? $data['name'] : '',
            status: is_string($data['status'] ?? null) ? $data['status'] : '',
            wireguardIp: is_string($data['wireguard_ip'] ?? null) ? $data['wireguard_ip'] : null,
            lanIp: is_string($data['lan_ip'] ?? null) ? $data['lan_ip'] : null,
        );
    }

    /** @return array{id: int, name: string, status: string, wireguard_ip: string|null, lan_ip: string|null} */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'status' => $this->status,
            'wireguard_ip' => $this->wireguardIp,
            'lan_ip' => $this->lanIp,
        ];
    }
}
