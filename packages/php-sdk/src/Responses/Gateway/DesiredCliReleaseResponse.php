<?php

declare(strict_types=1);

namespace Orbit\Sdk\Responses\Gateway;

use InvalidArgumentException;

/**
 * The CLI release the fleet should run. `status` is `available` with a version, tag, checksum file, and one
 * binary per platform; `pending` with the version and tag of a release CI has not published yet; or
 * `unavailable` with a `reason` and nothing else. `commit` is the commit CI built the release from. An
 * available release of another commit than the Gateway's is a fallback to an ancestor's release.
 */
final readonly class DesiredCliReleaseResponse
{
    /** @param  list<FleetReleaseAssetResponse>  $assets */
    public function __construct(
        public string $status,
        public ?string $reason,
        public ?string $version,
        public ?string $tag,
        public ?string $checksumsUrl,
        public array $assets,
        public ?string $commit = null,
    ) {}

    /** @throws InvalidArgumentException */
    public static function fromGatewayData(mixed $data): self
    {
        if (! is_array($data)) {
            throw new InvalidArgumentException('The desired CLI release is invalid.');
        }

        $status = $data['status'] ?? null;
        $reason = $data['reason'] ?? null;
        $commit = $data['commit'] ?? null;

        if ($commit !== null && (! is_string($commit) || preg_match('/\A[0-9a-f]{40}\z/D', $commit) !== 1)) {
            throw new InvalidArgumentException('The desired CLI release is invalid.');
        }

        if ($status === 'unavailable') {
            if (! is_string($reason) || preg_match('/\A[a-z][a-z0-9_]{0,63}\z/D', $reason) !== 1) {
                throw new InvalidArgumentException('The desired CLI release is invalid.');
            }

            return new self('unavailable', $reason, null, null, null, []);
        }

        $version = $data['version'] ?? null;
        $tag = $data['tag'] ?? null;
        $checksumsUrl = $data['checksums_url'] ?? null;

        if ($status === 'pending') {
            if (
                ! is_string($reason) || preg_match('/\A[a-z][a-z0-9_]{0,63}\z/D', $reason) !== 1
                || ! is_string($version) || preg_match('/\A0\.[1-9][0-9]{0,9}\.0\z/D', $version) !== 1
                || $tag !== 'cli-v'.$version
            ) {
                throw new InvalidArgumentException('The desired CLI release is invalid.');
            }

            return new self('pending', $reason, $version, $tag, null, [], $commit);
        }

        if (
            $status !== 'available'
            || ! is_string($version) || preg_match('/\A0\.[1-9][0-9]{0,9}\.0\z/D', $version) !== 1
            || $tag !== 'cli-v'.$version
            || ! is_string($checksumsUrl) || ! str_starts_with($checksumsUrl, 'https://') || preg_match('/[\x00-\x20\x7F]/', $checksumsUrl) === 1
        ) {
            throw new InvalidArgumentException('The desired CLI release is invalid.');
        }

        return new self('available', null, $version, $tag, $checksumsUrl, FleetReleaseAssetResponse::listFromGatewayData($data['assets'] ?? null), $commit);
    }

    public function isAvailable(): bool
    {
        return $this->status === 'available';
    }

    /** CI has not published the release yet; it will be there minutes after the Gateway's checks passed. */
    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function asset(string $platform): ?FleetReleaseAssetResponse
    {
        return array_find($this->assets, static fn (FleetReleaseAssetResponse $asset): bool => $asset->platform === $platform);
    }

    /** @return array{status: string, reason: ?string, version: ?string, tag: ?string, commit: ?string, checksums_url: ?string, assets: list<array{platform: string, name: string, url: string, sha256: string}>} */
    public function toArray(): array
    {
        return [
            'status' => $this->status,
            'reason' => $this->reason,
            'version' => $this->version,
            'tag' => $this->tag,
            'commit' => $this->commit,
            'checksums_url' => $this->checksumsUrl,
            'assets' => array_map(static fn (FleetReleaseAssetResponse $asset): array => $asset->toArray(), $this->assets),
        ];
    }
}
