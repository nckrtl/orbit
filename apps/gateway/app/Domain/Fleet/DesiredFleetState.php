<?php

declare(strict_types=1);

namespace App\Domain\Fleet;

use App\Data\Fleet\DesiredAgentData;
use App\Data\Fleet\DesiredCliReleaseData;
use App\Data\Fleet\DesiredFleetStateData;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Config;

/**
 * The desired fleet state of the running Gateway (ADR 0202): its commit, the CLI release CI published for that
 * commit, and the pinned agent. The release endpoint, the scheduler, and the fleet rollout resolve it here;
 * `gateway:status` and the client version header read only what is cached.
 *
 * A published release never changes, so a confirmed CLI release stays cached for its commit. A release that is
 * not there yet is asked for again after a minute, because CI publishes it a few minutes after the checks pass.
 *
 * GitHub can refuse to publish a commit's release, for example after a newer commit reached `main` with other
 * workflow files. When the commit's release has been missing, mismatched, or incomplete for
 * {@see self::FallbackAfterSeconds}, the state names the newest published release of an ancestor whose CLI
 * build inputs ({@see CliReleaseName::BuildInputs}) are the same, so its binary is built from the same CLI
 * code. The fleet rollout then does not wait for a release that never comes. The Gateway keeps that fallback
 * for the commit, and asks for the commit's own release every {@see self::FallbackSeconds} until it appears.
 */
final readonly class DesiredFleetState
{
    public const int AvailableSeconds = 30 * 24 * 60 * 60;

    public const int UnavailableSeconds = 60;

    /** How long one resolution may hold the lock, and how long another caller waits for it. */
    public const int LockSeconds = 60;

    public const int LockWaitSeconds = 30;

    /** How long the commit's own release may be missing before the state names an ancestor's release: 30 minutes. */
    public const int FallbackAfterSeconds = 30 * 60;

    /** How long a fallback answer is kept before the commit's own release is asked for again: 5 minutes. */
    public const int FallbackSeconds = 5 * 60;

    /** How many of the commit's ancestors the fallback searches for a published release. */
    public const int FallbackDepth = 50;

    /** The reasons a commit's release can stay missing for good, so a fallback may stand in for it. */
    private const array FallbackReasons = [
        CliReleaseUnavailableReason::ReleaseMissing,
        CliReleaseUnavailableReason::ReleaseMismatch,
        CliReleaseUnavailableReason::ReleaseIncomplete,
    ];

    private const string CacheKey = 'orbit:desired-fleet-state:v1:';

    private const string MissingSinceKey = self::CacheKey.'missing-since:';

    private const string FallbackKey = self::CacheKey.'fallback:';

    public function __construct(
        private ReleaseHistory $history,
        private CliReleaseCatalog $catalog,
        private Repository $cache,
    ) {}

    /** Resolves the state, asking Git and GitHub when the cache has no answer for this commit. */
    public function current(): DesiredFleetStateData
    {
        $revision = $this->revision();
        $cached = $this->read($revision);

        if ($cached instanceof DesiredFleetStateData) {
            return $cached;
        }

        // One caller resolves a cold commit; the others wait for its answer instead of asking GitHub too.
        $store = $this->cache->getStore();

        if (! $store instanceof LockProvider) {
            return $this->resolveAndStore($revision);
        }

        $lock = $store->lock(self::CacheKey.'lock:'.hash('sha256', $revision), self::LockSeconds);

        try {
            $lock->block(self::LockWaitSeconds);
        } catch (LockTimeoutException) {
            return $this->read($revision) ?? $this->resolveAndStore($revision);
        }

        try {
            return $this->read($revision) ?? $this->resolveAndStore($revision);
        } finally {
            $lock->release();
        }
    }

    private function resolveAndStore(string $revision): DesiredFleetStateData
    {
        [$commit, $cli, $seconds] = $this->resolve($revision);
        $this->cache->put($this->key($revision), ['commit' => $commit, 'cli' => $cli->toArray()], $seconds);

        return new DesiredFleetStateData($commit, $cli, DesiredAgentData::fromFootprint());
    }

    /** The state from the cache alone, without Git or GitHub, or null when nothing is cached for this commit. */
    public function cached(): ?DesiredFleetStateData
    {
        return $this->read($this->revision());
    }

    private function revision(): string
    {
        return trim(Config::string('app.version'));
    }

    private function read(string $revision): ?DesiredFleetStateData
    {
        $stored = $this->cache->get($this->key($revision));

        if (! is_array($stored)) {
            return null;
        }

        $commit = $stored['commit'] ?? null;
        $cli = DesiredCliReleaseData::fromArray($stored['cli'] ?? null);

        if (! $cli instanceof DesiredCliReleaseData || ($commit !== null && ! is_string($commit))) {
            return null;
        }

        return new DesiredFleetStateData($commit, $cli, DesiredAgentData::fromFootprint());
    }

    /**
     * The commit, its CLI release, and how long to keep that answer.
     *
     * @return array{?string, DesiredCliReleaseData, int}
     */
    private function resolve(string $revision): array
    {
        if (preg_match('/\A[0-9a-f]{7,40}\z/D', $revision) !== 1) {
            return [null, DesiredCliReleaseData::unavailable(CliReleaseUnavailableReason::GatewayCommitUnknown), self::UnavailableSeconds];
        }

        $commit = $this->history->commit($revision);

        if ($commit === null) {
            return [
                strlen($revision) === 40 ? $revision : null,
                DesiredCliReleaseData::unavailable(CliReleaseUnavailableReason::GatewayCommitUnknown),
                self::UnavailableSeconds,
            ];
        }

        $count = $this->history->count($commit);

        if ($count === null || $count < 1) {
            return [$commit, DesiredCliReleaseData::unavailable(CliReleaseUnavailableReason::HistoryUnavailable), self::UnavailableSeconds];
        }

        $release = $this->catalog->find($commit, new CliReleaseName($count));

        if ($release->isAvailable()) {
            return [$commit, $release, self::AvailableSeconds];
        }

        // A fallback, once found, stays until the commit's own release appears. A GitHub error never drops it.
        $kept = DesiredCliReleaseData::fromArray($this->cache->get(self::FallbackKey.hash('sha256', $revision)));

        if ($kept instanceof DesiredCliReleaseData && $kept->isAvailable()) {
            return [$commit, $kept, self::FallbackSeconds];
        }

        if (! in_array($release->reason, self::FallbackReasons, true) || ! $this->missingLongEnough($revision)) {
            return [$commit, $release, self::UnavailableSeconds];
        }

        [$fallback, $searched] = $this->fallback($commit);

        if (! $fallback instanceof DesiredCliReleaseData) {
            // A search that found nothing runs again after a while, not on every lookup.
            return [$commit, $release, $searched ? self::FallbackSeconds : self::UnavailableSeconds];
        }

        $this->cache->put(self::FallbackKey.hash('sha256', $revision), $fallback->toArray(), self::AvailableSeconds);

        return [$commit, $fallback, self::FallbackSeconds];
    }

    /**
     * The newest published release of an ancestor whose CLI build inputs match the commit's. Release numbers grow
     * along `main`, so the highest number is the newest. Git filters the candidates before GitHub sees any.
     * The search stops at the first ancestor GitHub cannot answer for, and names nothing rather than an older
     * guess. Returns the release, and whether the search completed.
     *
     * @return array{?DesiredCliReleaseData, bool}
     */
    private function fallback(string $commit): array
    {
        $candidates = [];

        foreach ($this->history->ancestors($commit, self::FallbackDepth) as $ancestor) {
            $number = $this->history->count($ancestor);

            if ($number !== null && $number > 0) {
                $candidates[] = [$ancestor, $number];
            }
        }

        usort($candidates, static fn (array $left, array $right): int => $right[1] <=> $left[1]);

        foreach ($candidates as [$ancestor, $number]) {
            if (! $this->history->unchanged($ancestor, $commit, CliReleaseName::BuildInputs)) {
                continue;
            }

            $found = $this->catalog->find($ancestor, new CliReleaseName($number));

            if ($found->isAvailable()) {
                return [$found, true];
            }

            if ($found->reason === CliReleaseUnavailableReason::GitHubUnavailable) {
                return [null, false];
            }
        }

        return [null, true];
    }

    /** Whether the commit's own release has been missing for {@see self::FallbackAfterSeconds}, from the first time it was. */
    private function missingLongEnough(string $revision): bool
    {
        $key = self::MissingSinceKey.hash('sha256', $revision);
        $this->cache->add($key, now()->getTimestamp(), self::AvailableSeconds);
        $since = $this->cache->get($key);

        // Redis returns the stored number as a numeric string.
        return is_numeric($since) && now()->getTimestamp() - (int) $since >= self::FallbackAfterSeconds;
    }

    private function key(string $revision): string
    {
        return self::CacheKey.hash('sha256', $revision);
    }
}
