<?php

declare(strict_types=1);

namespace App\Support\Realtime;

/** A fixed channel whose subscription the Gateway's channel authorization endpoint signs. */
final readonly class AuthorizedRealtimeChannel implements RealtimeChannelOpener
{
    public function __construct(
        private string $channel,
        private RealtimeChannelAuthorizer $authorizer,
    ) {}

    #[\Override]
    public function open(string $socketId): RealtimeChannelGrant
    {
        return new RealtimeChannelGrant($this->channel, $this->authorizer->authorize($socketId, $this->channel));
    }
}
