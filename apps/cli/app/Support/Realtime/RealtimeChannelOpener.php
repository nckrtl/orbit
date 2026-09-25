<?php

declare(strict_types=1);

namespace App\Support\Realtime;

/**
 * Names the private channel one WebSocket connection joins and signs its subscription. The
 * subscriber calls open() once per connection, after Reverb reports the connection's socket ID.
 */
interface RealtimeChannelOpener
{
    /**
     * @throws RealtimeConnectionException when the grant cannot be requested; the subscriber reconnects.
     * @throws RealtimeProtocolException when the grant is unusable; the subscriber reconnects.
     *
     * Any other exception propagates out of RealtimeSubscriber::poll() to its caller, which then
     * closes the subscriber.
     */
    public function open(string $socketId): RealtimeChannelGrant;
}
