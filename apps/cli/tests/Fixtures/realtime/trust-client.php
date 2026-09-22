<?php

declare(strict_types=1);

use App\Support\Realtime\GatewayChannelAuthorizer;
use App\Support\Realtime\RealtimeConnectionException;
use App\Support\Realtime\RealtimeProtocolException;
use App\Support\Realtime\StreamWebSocketTransport;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../../../vendor/autoload.php';
$app = require __DIR__.'/../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$configuration = json_decode(file_get_contents($argv[1]), true, flags: JSON_THROW_ON_ERROR);
$attempts = [];
$transport = new StreamWebSocketTransport;

try {
    for ($attempt = 0; $attempt < (($configuration['remove_pin'] ?? false) ? 2 : 1); $attempt++) {
        if ($attempt === 1) {
            unlink($configuration['pin']);
        }

        try {
            if ($configuration['mode'] === 'auth') {
                $auth = new GatewayChannelAuthorizer($configuration['url'], $configuration['pin'], 1)->authorize('1.1', 'private-orbit');
                $attempts[] = ['status' => 'authorized', 'auth' => $auth];
            } else {
                $transport->connect($configuration['url'], $configuration['pin'], 1);
                $attempts[] = ['status' => 'connected'];
                $transport->close();
            }
        } catch (RealtimeConnectionException|RealtimeProtocolException $exception) {
            $attempts[] = ['status' => 'failed', 'type' => $exception::class, 'message' => $exception->getMessage()];
        }
    }
} finally {
    $transport->close();
}

echo json_encode($attempts, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n";
