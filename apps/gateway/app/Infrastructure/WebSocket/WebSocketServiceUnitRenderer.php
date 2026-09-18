<?php

declare(strict_types=1);

namespace App\Infrastructure\WebSocket;

/** Renders the systemd unit that keeps `reverb:start` running on the node. */
final readonly class WebSocketServiceUnitRenderer
{
    public function render(string $installPath, string $managedUser, string $managedGroup, int $port): string
    {
        return <<<UNIT
            # Managed by Orbit. Do not edit; the websocket role converge overwrites this file.
            [Unit]
            Description=Orbit websocket role: Reverb realtime server
            After=network.target

            [Service]
            Type=simple
            User={$managedUser}
            Group={$managedGroup}
            WorkingDirectory={$installPath}
            ExecStart=/usr/bin/php {$installPath}/artisan reverb:start --host=127.0.0.1 --port={$port}
            Restart=always
            RestartSec=1

            [Install]
            WantedBy=multi-user.target

            UNIT;
    }
}
