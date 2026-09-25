<?php

declare(strict_types=1);

namespace App\Infrastructure\AgentView;

use App\Domain\AgentView\AgentNodeView;
use App\Domain\AgentView\AgentStateView;
use App\Domain\AgentView\AgentViewFreshness;
use App\Domain\AgentView\AgentViewSubscriberHealth;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Keeps the Gateway's view of the Node agents in the Gateway's default cache store, which the
 * agent view subscriber shares with every PHP-FPM worker.
 *
 * Freshness uses only the Gateway clock: the time the subscriber received the agent's last event.
 * A Node whose own clock is wrong therefore still reads as fresh.
 */
final readonly class CacheAgentStateView implements AgentStateView
{
    /** Seconds a Node entry outlives its last write, so a dead subscriber leaves nothing behind. */
    public const int NodeTtlSeconds = 60;

    private const string NODE_KEY = 'agent-view.node.';

    private const string SUBSCRIBER_KEY = 'agent-view.subscriber';

    public function __construct(private Repository $cache) {}

    #[\Override]
    public function node(int $nodeId): AgentNodeView
    {
        try {
            $entry = $this->cache->get(self::NODE_KEY.$nodeId);
        } catch (Throwable) {
            return AgentNodeView::missing($nodeId);
        }

        if (! is_array($entry) || ! is_numeric($entry['received_at'] ?? null) || ! is_array($entry['units'] ?? null)) {
            return AgentNodeView::missing($nodeId);
        }

        $receivedAt = (float) $entry['received_at'];
        $fresh = self::now() - $receivedAt <= self::FreshSeconds;
        /** @var array<string, string> $units */
        $units = array_filter($entry['units'], is_string(...));
        $docker = is_string($entry['docker'] ?? null) ? $entry['docker'] : null;

        return new AgentNodeView(
            nodeId: $nodeId,
            freshness: $fresh ? AgentViewFreshness::Fresh : AgentViewFreshness::Stale,
            units: $units,
            docker: $docker,
            receivedAt: $receivedAt,
        );
    }

    #[\Override]
    public function subscriber(): ?AgentViewSubscriberHealth
    {
        try {
            $entry = $this->cache->get(self::SUBSCRIBER_KEY);
        } catch (Throwable) {
            return null;
        }

        if (! is_array($entry) || ! is_numeric($entry['updated_at'] ?? null)) {
            return null;
        }

        return new AgentViewSubscriberHealth(
            updatedAt: (float) $entry['updated_at'],
            configured: ($entry['configured'] ?? false) === true,
            connected: ($entry['connected'] ?? false) === true,
            channels: is_int($entry['channels'] ?? null) ? $entry['channels'] : 0,
        );
    }

    /**
     * Writes one Node's complete state.
     *
     * @param  array<string, string>  $units
     */
    public function putNode(int $nodeId, array $units, ?string $docker, int $sequence, float $receivedAt, ?string $agentAt): void
    {
        $this->cache->put(self::NODE_KEY.$nodeId, [
            'received_at' => $receivedAt,
            'agent_at' => $agentAt,
            'sequence' => $sequence,
            'docker' => $docker,
            'units' => $units,
        ], self::NodeTtlSeconds);
    }

    public function forgetNode(int $nodeId): void
    {
        $this->cache->forget(self::NODE_KEY.$nodeId);
    }

    public function putSubscriber(bool $configured, bool $connected, int $channels): void
    {
        $this->cache->put(self::SUBSCRIBER_KEY, [
            'updated_at' => self::now(),
            'pid' => getmypid(),
            'configured' => $configured,
            'connected' => $connected,
            'channels' => $channels,
        ], self::SubscriberSeconds);
    }

    /** Removes the subscriber's health, as when it stops: Doctor then reports it down at once. */
    public function forgetSubscriber(): void
    {
        $this->cache->forget(self::SUBSCRIBER_KEY);
    }

    /** The Gateway clock in seconds, with Carbon's test time in tests. */
    public static function now(): float
    {
        return (float) Carbon::now()->format('U.u');
    }
}
