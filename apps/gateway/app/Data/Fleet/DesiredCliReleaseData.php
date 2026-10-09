<?php

declare(strict_types=1);

namespace App\Data\Fleet;

use App\Domain\Fleet\CliReleaseName;
use App\Domain\Fleet\CliReleaseUnavailableReason;
use App\Domain\Fleet\DesiredCliReleaseStatus;
use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

/**
 * The CLI release of the Gateway's commit. While it is `pending`, `reason` is `release_missing` and only the
 * version, tag, and commit are known. While it is `unavailable`, `reason` says why and every other field is null
 * or empty. An `available` release whose `commit` differs from the Gateway's commit is a fallback: the commit's
 * own release never appeared, and this is the newest published release of a commit it reaches. A fallback's
 * `reason` says why the commit's own release was not used; an own release has none.
 */
#[MapOutputName(SnakeCaseMapper::class)]
final class DesiredCliReleaseData extends Data
{
    /**
     * @param  list<FleetReleaseAssetData>  $assets
     */
    public function __construct(
        public DesiredCliReleaseStatus $status,
        public ?CliReleaseUnavailableReason $reason,
        /** The release version `0.N.0`, or null while unavailable. */
        public ?string $version,
        /** The release tag `cli-v0.N.0`, or null while unavailable. */
        public ?string $tag,
        /** The full SHA of the commit CI built the release from, or null while unavailable or in an older record. */
        public ?string $commit,
        /** The download URL of the release's `SHA256SUMS`, or null until the release is available. */
        public ?string $checksumsUrl,
        /** One binary per platform, empty until the release is available. */
        public array $assets,
    ) {}

    /**
     * @param  list<FleetReleaseAssetData>  $assets
     */
    public static function available(CliReleaseName $release, ?string $commit, string $checksumsUrl, array $assets): self
    {
        return new self(DesiredCliReleaseStatus::Available, null, $release->version(), $release->tag(), $commit, $checksumsUrl, $assets);
    }

    /** The release CI has not published yet. */
    public static function pending(CliReleaseName $release, ?string $commit): self
    {
        return new self(DesiredCliReleaseStatus::Pending, CliReleaseUnavailableReason::ReleaseMissing, $release->version(), $release->tag(), $commit, null, []);
    }

    /** This available release, standing in for the commit's own release, which was not used for the reason. */
    public function fallbackFor(CliReleaseUnavailableReason $reason): self
    {
        return new self($this->status, $reason, $this->version, $this->tag, $this->commit, $this->checksumsUrl, $this->assets);
    }

    public static function unavailable(CliReleaseUnavailableReason $reason): self
    {
        return new self(DesiredCliReleaseStatus::Unavailable, $reason, null, null, null, null, []);
    }

    public function isAvailable(): bool
    {
        return $this->status === DesiredCliReleaseStatus::Available;
    }

    public static function fromArray(mixed $value): ?self
    {
        if (! is_array($value)) {
            return null;
        }

        $status = is_string($value['status'] ?? null) ? DesiredCliReleaseStatus::tryFrom($value['status']) : null;
        // A record stored before releases named their commit has none.
        $commit = $value['commit'] ?? null;

        if ($commit !== null && (! is_string($commit) || preg_match('/\A[0-9a-f]{40}\z/D', $commit) !== 1)) {
            return null;
        }

        if ($status === DesiredCliReleaseStatus::Pending) {
            $version = $value['version'] ?? null;

            return is_string($version) && preg_match('/\A0\.([1-9][0-9]{0,9})\.0\z/D', $version, $matches) === 1
                ? self::pending(new CliReleaseName((int) $matches[1]), $commit)
                : null;
        }

        if ($status === DesiredCliReleaseStatus::Unavailable) {
            $reason = is_string($value['reason'] ?? null) ? CliReleaseUnavailableReason::tryFrom($value['reason']) : null;

            return $reason instanceof CliReleaseUnavailableReason ? self::unavailable($reason) : null;
        }

        $version = $value['version'] ?? null;
        $tag = $value['tag'] ?? null;
        // Only a fallback has a reason; a fallback stored before fallbacks named one has none.
        $reason = is_string($value['reason'] ?? null) ? CliReleaseUnavailableReason::tryFrom($value['reason']) : null;
        $checksumsUrl = $value['checksums_url'] ?? null;
        $assets = FleetReleaseAssetData::listFromArray($value['assets'] ?? null);

        if (
            $status !== DesiredCliReleaseStatus::Available
            || ! is_string($version) || preg_match('/\A0\.[1-9][0-9]*\.0\z/D', $version) !== 1
            || $tag !== 'cli-v'.$version
            || ! is_string($checksumsUrl) || ! str_starts_with($checksumsUrl, 'https://')
            || $assets === null || $assets === []
        ) {
            return null;
        }

        return new self($status, $reason, $version, $tag, $commit, $checksumsUrl, $assets);
    }
}
