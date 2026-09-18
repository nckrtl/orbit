<?php

declare(strict_types=1);

use App\Domain\WebSocket\WebSocketCredentials;
use App\Infrastructure\WebSocket\WebSocketEnvironmentRenderer;

it('renders every variable the Reverb app and reverb:start need', function (): void {
    $credentials = new WebSocketCredentials('app-id', 'app-key', 'app-secret', 'base64:'.base64_encode('k'));

    $env = new WebSocketEnvironmentRenderer()->render($credentials, 8790);

    expect($env)
        ->toContain('APP_ENV=production')
        ->toContain('APP_DEBUG=false')
        ->toContain('APP_KEY=base64:')
        ->toContain('CACHE_STORE=file')
        ->toContain('SESSION_DRIVER=file')
        ->toContain('QUEUE_CONNECTION=sync')
        ->toContain('BROADCAST_CONNECTION=reverb')
        ->toContain('LOG_CHANNEL=stderr')
        ->toContain('REVERB_APP_ID=app-id')
        ->toContain('REVERB_APP_KEY=app-key')
        ->toContain('REVERB_APP_SECRET=app-secret')
        ->toContain('REVERB_HOST=reverb.orbit')
        ->toContain('REVERB_PORT=443')
        ->toContain('REVERB_SCHEME=https')
        ->toContain('REVERB_SERVER_HOST=127.0.0.1')
        ->toContain('REVERB_SERVER_PORT=8790')
        ->toEndWith("\n");
});
