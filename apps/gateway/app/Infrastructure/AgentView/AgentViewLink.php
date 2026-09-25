<?php

declare(strict_types=1);

namespace App\Infrastructure\AgentView;

use App\Domain\Broadcasting\RealtimeConnectionData;

/**
 * One Pusher-protocol connection of the agent view subscriber to the Reverb server at one address.
 * During a `websocket` move two Nodes serve `reverb.orbit`, so the subscriber keeps one link to each.
 */
final class AgentViewLink
{
    /** @var array<int, AgentChannelState> Joined channels, keyed by Node id. */
    public array $channels = [];

    public ?string $socketId = null;

    public ?RealtimeConnectionData $connection = null;

    public float $backoff = 0.0;

    public float $nextConnectAt = 0.0;

    public float $lastMessageAt = 0.0;

    public ?float $pingSentAt = null;

    /** @var array<int, float> When the subscriber last asked each Node's agent on this server for a snapshot. */
    public array $snapshotRequestedAt = [];

    public function __construct(
        public readonly string $address,
        public readonly WebSocketClient $socket,
    ) {}

    public function isLive(): bool
    {
        return $this->socket->isConnected() && $this->socketId !== null;
    }

    /** Closes the socket and forgets every joined channel. */
    public function reset(): void
    {
        $this->socket->close();
        $this->channels = [];
        $this->snapshotRequestedAt = [];
        $this->socketId = null;
        $this->connection = null;
        $this->pingSentAt = null;
    }
}
