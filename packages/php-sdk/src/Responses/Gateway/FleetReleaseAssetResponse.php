<?php

declare(strict_types=1);

namespace Orbit\Sdk\Responses\Gateway;

use InvalidArgumentException;

/** One binary of the desired fleet state: its platform, release asset name, HTTPS download URL, and SHA-256. */
final readonly class FleetReleaseAssetResponse
{
    public function __construct(
        public string $platform,
        public string $name,
        public string $url,
        public string $sha256,
    ) {}

    /**
     * @return list<self>
     *
     * @throws InvalidArgumentException
     */
    public static function listFromGatewayData(mixed $data): array
    {
        if (! is_array($data) || ! array_is_list($data)) {
            throw new InvalidArgumentException('Desired fleet state assets are invalid.');
        }

        return array_map(self::fromGatewayData(...), $data);
    }

    /** @throws InvalidArgumentException */
    public static function fromGatewayData(mixed $data): self
    {
        $platform = is_array($data) ? ($data['platform'] ?? null) : null;
        $name = is_array($data) ? ($data['name'] ?? null) : null;
        $url = is_array($data) ? ($data['url'] ?? null) : null;
        $sha256 = is_array($data) ? ($data['sha256'] ?? null) : null;

        if (
            ! is_string($platform) || preg_match('/\A[a-z0-9]+-[a-z0-9_]+\z/D', $platform) !== 1
            || ! is_string($name) || preg_match('/\A[A-Za-z0-9._-]{1,255}\z/D', $name) !== 1
            || ! is_string($url) || ! str_starts_with($url, 'https://') || strlen($url) > 2048 || preg_match('/[\x00-\x20\x7F]/', $url) === 1
            || ! is_string($sha256) || preg_match('/\A[0-9a-f]{64}\z/D', $sha256) !== 1
        ) {
            throw new InvalidArgumentException('A desired fleet state asset is invalid.');
        }

        return new self($platform, $name, $url, $sha256);
    }

    /** @return array{platform: string, name: string, url: string, sha256: string} */
    public function toArray(): array
    {
        return ['platform' => $this->platform, 'name' => $this->name, 'url' => $this->url, 'sha256' => $this->sha256];
    }
}
