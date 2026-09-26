<?php

declare(strict_types=1);

namespace App\Infrastructure\AgentView;

/**
 * The work the agent view subscriber hands off so its socket loop never waits on Prometheus, the
 * database, or a broadcast (ADR 0151): storing task line counts, the task notices that follow, and
 * the Process usage sample. Every method returns at once.
 */
interface AgentViewPublisher
{
    /**
     * Queues the task workspaces whose `head` or diff changed.
     *
     * @param  list<int>  $instanceIds
     */
    public function queueWorkspaces(int $nodeId, array $instanceIds): void;

    /** Queues one Process usage sample; a newer sample replaces a waiting one. */
    public function queueUsage(int $sampledAt): void;

    /** Reaps a finished run, stops an overdue one, and starts the next when work waits. */
    public function poll(): void;

    /** Stops a running publish, as when the subscriber stops. */
    public function stop(): void;
}
