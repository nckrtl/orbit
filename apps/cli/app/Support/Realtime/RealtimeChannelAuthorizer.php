<?php

declare(strict_types=1);

namespace App\Support\Realtime;

/** Authorizes one WebSocket connection to subscribe to a private Pusher-protocol channel. */
interface RealtimeChannelAuthorizer
{
    /**
     * Return the `auth` signature the gateway issues for $socketId on $channelName.
     *
     * @throws RealtimeConnectionException when the authorization request cannot be sent.
     * @throws RealtimeProtocolException when the response omits a usable auth signature.
     */
    public function authorize(string $socketId, string $channelName): string;
}
