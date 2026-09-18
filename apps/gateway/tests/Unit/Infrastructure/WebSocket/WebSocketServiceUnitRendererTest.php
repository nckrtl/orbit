<?php

declare(strict_types=1);

use App\Infrastructure\WebSocket\WebSocketServiceUnitRenderer;

it('renders a systemd unit running reverb:start as the managed user with Restart=always', function (): void {
    $unit = new WebSocketServiceUnitRenderer()->render('/opt/orbit/websocket', 'orbit-websocket', 'orbit-websocket', 8790);

    expect($unit)
        ->toContain('User=orbit-websocket')
        ->toContain('Group=orbit-websocket')
        ->toContain('WorkingDirectory=/opt/orbit/websocket')
        ->toContain('ExecStart=/usr/bin/php /opt/orbit/websocket/artisan reverb:start --host=127.0.0.1 --port=8790')
        ->toContain('Restart=always')
        ->toContain('WantedBy=multi-user.target');
});
