<?php

declare(strict_types=1);

namespace App\Infrastructure\Fleet;

use App\Data\Fleet\DesiredCliReleaseData;
use App\Data\Fleet\FleetReleaseAssetData;
use App\Domain\Fleet\CliReleaseCatalog;
use App\Domain\Fleet\CliReleaseName;
use App\Domain\Fleet\CliReleaseUnavailableReason;
use App\Domain\Fleet\ReleaseHistory;

/**
 * Test-only: names one CLI release from a local manifest instead of the Gateway's Git history and
 * GitHub, for a disposable topology whose commit has no published release. It is bound only while
 * `ORBIT_CLI_RELEASE_STATIC_MANIFEST` names a file. Never set it on a real Gateway.
 *
 * The manifest is `{"number": 9001, "checksums_url": "https://...", "assets": [{"platform": "linux-x86_64",
 * "url": "https://...", "sha256": "..."}]}`. A missing or malformed file reports the release pending.
 */
final readonly class StaticCliRelease implements CliReleaseCatalog, ReleaseHistory
{
    public function __construct(private string $path) {}

    public function commit(string $revision): ?string
    {
        return preg_match('/\A[0-9a-f]{40}\z/D', $revision) === 1 ? $revision : null;
    }

    public function count(string $commit): ?int
    {
        $number = $this->manifest()['number'] ?? null;

        return is_int($number) && $number > 0 ? $number : null;
    }

    public function ancestors(string $commit, int $limit): array
    {
        return [];
    }

    public function unchanged(string $from, string $to, array $paths): bool
    {
        return false;
    }

    public function find(string $commit, CliReleaseName $release): DesiredCliReleaseData
    {
        $manifest = $this->manifest();
        $checksums = $manifest['checksums_url'] ?? null;
        $assets = [];

        foreach (is_array($manifest['assets'] ?? null) ? $manifest['assets'] : [] as $asset) {
            if (! is_array($asset) || ! is_string($asset['platform'] ?? null)) {
                continue;
            }

            $parsed = FleetReleaseAssetData::fromArray([...$asset, 'name' => $release->assetName($asset['platform'])]);

            if ($parsed instanceof FleetReleaseAssetData) {
                $assets[] = $parsed;
            }
        }

        if (! is_string($checksums) || $assets === []) {
            return DesiredCliReleaseData::unavailable(CliReleaseUnavailableReason::ReleaseIncomplete);
        }

        return DesiredCliReleaseData::available($release, $commit, $checksums, $assets);
    }

    /** @return array<array-key, mixed> */
    private function manifest(): array
    {
        $contents = @file_get_contents($this->path);

        if (! is_string($contents)) {
            return [];
        }

        $decoded = json_decode($contents, true);

        return is_array($decoded) ? $decoded : [];
    }
}
