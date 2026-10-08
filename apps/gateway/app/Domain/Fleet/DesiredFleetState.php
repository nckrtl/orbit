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
 * GitHub can refuse to publish a commit's release, for example when the commit is no longer the tip of `main`
 * and its workflow files differ from the tip's. When the commit's release has been missing, mismatched, or
 * incomplete for {@see self::FallbackAfterSeconds}, the state names the newest published release of a commit
 * this one reaches instead, so the fleet rollout does not wait for a release that never comes. That fallback is
 * asked for again every {@see self::FallbackSeconds}, and the commit's own release replaces it once it appears.
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
    public const int FallbackDepth = 20;

    /** The reasons a commit's release can stay missing for good, so a fallback may stand in for it. */
    private const array FallbackReasons = [
        CliReleaseUnavailableReason::ReleaseMissing,
        CliReleaseUnavailableReason::ReleaseMismatch,
        CliReleaseUnavailableReason::ReleaseIncomplete,
    ];

    private const string CacheKey = 'orbit:desired-fleet-state:v1:';

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
        [$commit, $cli] = $this->resolve($revision);
        $state = new DesiredFleetStateData($commit, $cli, DesiredAgentData::fromFootprint());
        $this->cache->put(
            $this->key($revision),
            ['commit' => $commit, 'cli' => $cli->toArray()],
            match (true) {
                $state->cliFallback() => self::FallbackSeconds,
                $cli->isAvailable() => self::AvailableSeconds,
                default => self::UnavailableSeconds,
            },
        );

        return $state;
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

    /** @return array{?string, DesiredCliReleaseData} */
    private function resolve(string $revision): array
    {
        if (preg_match('/\A[0-9a-f]{7,40}\z/D', $revision) !== 1) {
            return [null, DesiredCliReleaseData::unavailable(CliReleaseUnavailableReason::GatewayCommitUnknown)];
        }

        $commit = $this->history->commit($revision);

        if ($commit === null) {
            return [
                strlen($revision) === 40 ? $revision : null,
                DesiredCliReleaseData::unavailable(CliReleaseUnavailableReason::GatewayCommitUnknown),
            ];
        }

        $count = $this->history->count($commit);

        if ($count === null || $count < 1) {
            return [$commit, DesiredCliReleaseData::unavailable(CliReleaseUnavailableReason::HistoryUnavailable)];
        }

        $release = $this->catalog->find($commit, new CliReleaseName($count));

        return [$commit, $this->fallback($revision, $commit, $release) ?? $release];
    }

    /**
     * The newest published release of an ancestor, once the commit's own release has been missing for
     * {@see self::FallbackAfterSeconds}. Release numbers grow along `main`, so the highest number is the newest.
     * It stops at the first ancestor GitHub cannot answer for, and names nothing rather than an older guess.
     */
    private function fallback(string $revision, string $commit, DesiredCliReleaseData $release): ?DesiredCliReleaseData
    {
        if (! in_array($release->reason, self::FallbackReasons, true) || ! $this->missingLongEnough($revision)) {
            return null;
        }

        $candidates = [];

        foreach ($this->history->ancestors($commit, self::FallbackDepth) as $ancestor) {
            $number = $this->history->count($ancestor);

            if ($number !== null && $number > 0) {
                $candidates[] = [$ancestor, $number];
            }
        }

        usort($candidates, static fn (array $left, array $right): int => $right[1] <=> $left[1]);

        foreach ($candidates as [$ancestor, $number]) {
            $found = $this->catalog->find($ancestor, new CliReleaseName($number));

            if ($found->isAvailable()) {
                return $found;
            }

            if ($found->reason === CliReleaseUnavailableReason::GitHubUnavailable) {
                return null;
            }
        }

        return null;
    }

    /** Whether the commit's own release has been missing for {@see self::FallbackAfterSeconds}, from the first time it was. */
    private function missingLongEnough(string $revision): bool
    {
        $key = self::CacheKey.'missing-since:'.hash('sha256', $revision);
        $this->cache->add($key, now()->getTimestamp(), self::AvailableSeconds);
        $since = $this->cache->get($key);

        return is_int($since) && now()->getTimestamp() - $since >= self::FallbackAfterSeconds;
    }

    private function key(string $revision): string
    {
        return self::CacheKey.hash('sha256', $revision);
    }
}
