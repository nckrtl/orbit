<?php

declare(strict_types=1);

namespace App\Infrastructure\AgentView;

use Illuminate\Contracts\Cache\Repository;

/**
 * The version each Node agent last reported when it joined its presence channel. The agent sends it
 * with every channel authorization, so a restarted agent reports its new version at once. An agent that
 * stays connected for weeks never joins again, so the entry has no expiry. It lives in the agent view's
 * file store and never touches the database.
 */
final readonly class AgentReportedVersions
{
    private const string KEY = 'agent-view.version.';

    public function __construct(private Repository $cache) {}

    public function record(int $nodeId, string $version): void
    {
        $this->cache->forever(self::KEY.$nodeId, ['version' => $version, 'at' => microtime(true)]);
    }

    /** @return array{version: string, at: float}|null */
    public function get(int $nodeId): ?array
    {
        $entry = $this->cache->get(self::KEY.$nodeId);

        if (! is_array($entry) || ! is_string($entry['version'] ?? null) || ! is_numeric($entry['at'] ?? null)) {
            return null;
        }

        return ['version' => $entry['version'], 'at' => (float) $entry['at']];
    }
}
