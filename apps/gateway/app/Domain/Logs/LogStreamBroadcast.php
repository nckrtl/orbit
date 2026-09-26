<?php

declare(strict_types=1);

namespace App\Domain\Logs;

use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

/**
 * One server event for live log streams: `log.lines` or `log.ended` on a viewer's
 * `private-log-stream.{id}` channel, or `log-streams.changed` on an agent's
 * `presence-node-logs.{id}` channel. Only the Reverb app secret can publish a server event, so a
 * client that accepts only these names knows they came from the Gateway.
 */
final readonly class LogStreamBroadcast implements ShouldBroadcastNow
{
    /** @param array<string, mixed> $payload */
    public function __construct(
        public string $channel,
        public string $name,
        public array $payload,
    ) {}

    /** @return list<Channel> */
    public function broadcastOn(): array
    {
        return [new Channel($this->channel)];
    }

    public function broadcastAs(): string
    {
        return $this->name;
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return $this->payload;
    }
}
