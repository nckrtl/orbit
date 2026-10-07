<?php

declare(strict_types=1);

namespace App\Domain\Fleet;

use App\Data\Fleet\DesiredAgentData;
use App\Data\Fleet\DesiredCliReleaseData;
use App\Data\Fleet\DesiredFleetStateData;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Config;

/**
 * The desired fleet state of the running Gateway (ADR 0202): its commit, the CLI release CI published for that
 * commit, and the pinned agent. The release endpoint, `gateway:status`, the client version header, and the
 * fleet rollout all read it here.
 *
 * A published release never changes, so a confirmed CLI release stays cached for its commit. A release that is
 * not there yet is asked for again after a minute, because CI publishes it a few minutes after the checks pass.
 */
final readonly class DesiredFleetState
{
    public const int AvailableSeconds = 30 * 24 * 60 * 60;

    public const int UnavailableSeconds = 60;

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

        [$commit, $cli] = $this->resolve($revision);
        $this->cache->put(
            $this->key($revision),
            ['commit' => $commit, 'cli' => $cli->toArray()],
            $cli->isAvailable() ? self::AvailableSeconds : self::UnavailableSeconds,
        );

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

        return [$commit, $this->catalog->find($commit, new CliReleaseName($count))];
    }

    private function key(string $revision): string
    {
        return self::CacheKey.hash('sha256', $revision);
    }
}
