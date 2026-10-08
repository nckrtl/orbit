<?php

declare(strict_types=1);

namespace App\Data\Fleet;

use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

/** One downloadable binary of the desired fleet state and the SHA-256 it must have. */
#[MapOutputName(SnakeCaseMapper::class)]
final class FleetReleaseAssetData extends Data
{
    public function __construct(
        /** The platform the binary runs on: `linux-x86_64`, `linux-aarch64`, or `macos-arm64`. */
        public string $platform,
        /** The release asset name, such as `orbit-0.4681.0-linux-x86_64`. */
        public string $name,
        /** The public HTTPS download URL of the asset. */
        public string $url,
        /** The lowercase hexadecimal SHA-256 of the binary. */
        public string $sha256,
    ) {}

    public static function fromArray(mixed $value): ?self
    {
        if (! is_array($value)) {
            return null;
        }

        $platform = $value['platform'] ?? null;
        $name = $value['name'] ?? null;
        $url = $value['url'] ?? null;
        $sha256 = $value['sha256'] ?? null;

        if (
            ! is_string($platform) || ! is_string($name) || ! is_string($url) || ! str_starts_with($url, 'https://')
            || ! is_string($sha256) || preg_match('/\A[0-9a-f]{64}\z/D', $sha256) !== 1
        ) {
            return null;
        }

        return new self($platform, $name, $url, $sha256);
    }

    /**
     * @return list<self>|null
     */
    public static function listFromArray(mixed $value): ?array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            return null;
        }

        $assets = [];

        foreach ($value as $row) {
            $asset = self::fromArray($row);

            if (! $asset instanceof self) {
                return null;
            }

            $assets[] = $asset;
        }

        return $assets;
    }
}
