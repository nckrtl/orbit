<?php

declare(strict_types=1);

namespace App\Domain\WebSocket;

/**
 * The one reserved private hostname Reverb answers on, wherever the
 * websocket role's node currently is.
 */
final readonly class WebSocketHostname
{
    public const string Value = 'reverb.orbit';
}
