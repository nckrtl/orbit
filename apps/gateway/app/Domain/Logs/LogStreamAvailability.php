<?php

declare(strict_types=1);

namespace App\Domain\Logs;

use App\Domain\AgentView\AgentStateView;
use App\Domain\Broadcasting\RealtimeConnection;

/**
 * Whether the Gateway can serve a live log stream for a Node now, and why not (ADR 0153).
 *
 * A stream needs Reverb, a running and connected agent view subscriber, a fresh view of the Node,
 * and an agent that joined the Node's log channel. An agent that has not joined yet, for example
 * while it reconnects during a `websocket` move, reports `agent_not_joined`, which clients try again later.
 */
final readonly class LogStreamAvailability
{
    public function __construct(
        private RealtimeConnection $realtime,
        private AgentStateView $view,
    ) {}

    /** Null when the Node can stream, otherwise the `logs.live_unavailable` reason. */
    public function unavailableReason(int $nodeId): ?string
    {
        if ($this->realtime->resolve() === null) {
            return 'realtime_not_configured';
        }

        $subscriber = $this->view->subscriber();

        if ($subscriber === null || ! $subscriber->isCurrent() || ! $subscriber->connected) {
            return 'subscriber_down';
        }

        $node = $this->view->node($nodeId);

        if (! $node->isFresh()) {
            return 'agent_unavailable';
        }

        if ($node->logs) {
            return null;
        }

        return 'agent_not_joined';
    }
}
