<?php

declare(strict_types=1);

namespace App\Infrastructure\WebSocket;

use App\Domain\WebSocket\WebSocketCredentials;
use App\Domain\WebSocket\WebSocketHostname;

/**
 * Renders the plain Reverb app's own `.env` file. The app is an ordinary
 * Laravel app with no Orbit-specific code, so it reads exactly the
 * environment variables Laravel and `reverb:start` already understand.
 */
final readonly class WebSocketEnvironmentRenderer
{
    public function render(WebSocketCredentials $credentials, int $port): string
    {
        $host = WebSocketHostname::Value;

        $lines = [
            '# Managed by Orbit. Do not edit; the websocket role converge overwrites this file.',
            'APP_ENV=production',
            'APP_DEBUG=false',
            "APP_KEY={$credentials->laravelAppKey}",
            'LOG_CHANNEL=stderr',
            'BROADCAST_CONNECTION=reverb',
            // The app has no database, so every store Laravel defaults to `database` is pinned.
            'CACHE_STORE=file',
            'SESSION_DRIVER=file',
            'QUEUE_CONNECTION=sync',
            "REVERB_APP_ID={$credentials->appId}",
            "REVERB_APP_KEY={$credentials->appKey}",
            "REVERB_APP_SECRET={$credentials->appSecret}",
            "REVERB_HOST={$host}",
            'REVERB_PORT=443',
            'REVERB_SCHEME=https',
            'REVERB_SERVER_HOST=127.0.0.1',
            "REVERB_SERVER_PORT={$port}",
        ];

        return implode("\n", $lines)."\n";
    }
}
