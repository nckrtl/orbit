<?php

declare(strict_types=1);

namespace App\Support\Realtime;

enum RealtimeState: string
{
    /** Subscribed to the realtime channel and able to receive events. */
    case Connected = 'connected';

    /** Not currently subscribed. Either the initial connection has not finished, or a dropped
     * socket is waiting for its next backoff attempt. Automatic reconnection is in progress. */
    case Reconnecting = 'reconnecting';

    /** No realtime endpoint and key are available for the active gateway. Callers should fall
     * back to polling instead of waiting for this state to change. */
    case NotConfigured = 'not_configured';
}
