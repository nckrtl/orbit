<?php

declare(strict_types=1);

namespace Orbit\Sdk\Responses\Gateway;

use InvalidArgumentException;

/** The `orbit-agent` version the Gateway pins and one Linux binary per architecture. */
final readonly class DesiredAgentResponse
{
    /** @param  list<FleetReleaseAssetResponse>  $assets */
    public function __construct(
        public string $version,
        public array $assets,
    ) {}

    /** @throws InvalidArgumentException */
    public static function fromGatewayData(mixed $data): self
    {
        $version = is_array($data) ? ($data['version'] ?? null) : null;

        if (! is_array($data) || ! is_string($version) || preg_match('/\A[0-9]+\.[0-9]+\.[0-9]+\z/D', $version) !== 1) {
            throw new InvalidArgumentException('The desired agent is invalid.');
        }

        return new self($version, FleetReleaseAssetResponse::listFromGatewayData($data['assets'] ?? null));
    }

    public function asset(string $platform): ?FleetReleaseAssetResponse
    {
        return array_find($this->assets, static fn (FleetReleaseAssetResponse $asset): bool => $asset->platform === $platform);
    }

    /** @return array{version: string, assets: list<array{platform: string, name: string, url: string, sha256: string}>} */
    public function toArray(): array
    {
        return [
            'version' => $this->version,
            'assets' => array_map(static fn (FleetReleaseAssetResponse $asset): array => $asset->toArray(), $this->assets),
        ];
    }
}
