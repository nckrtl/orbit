<?php

declare(strict_types=1);

namespace App\Domain\AgentView;

use App\Infrastructure\AgentView\CacheAgentStateView;

/**
 * The Gateway's view of what each Node agent last reported, as ADR 0148 defines it.
 *
 * The agent view subscriber writes it; readers use it only while it is fresh and fall back to
 * Prometheus or SSH otherwise. Every change to a Node still runs over SSH.
 *
 * @see CacheAgentStateView
 */
interface AgentStateView
{
    /** Seconds after the last agent event during which a view is fresh. */
    public const int FreshSeconds = 15;

    /** Seconds after its last write during which the subscriber counts as running. */
    public const int SubscriberSeconds = 30;

    public function node(int $nodeId): AgentNodeView;

    public function subscriber(): ?AgentViewSubscriberHealth;
}
