<?php

declare(strict_types=1);

use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use Illuminate\Contracts\Console\Kernel;
use Orbit\Sdk\GatewayApiException;
use Orbit\Sdk\Requests\AppInstances\RegisterAppInstanceRequest;

require '/home/orbit/orbit/apps/cli/vendor/autoload.php';
$laravel = require '/home/orbit/orbit/apps/cli/bootstrap/app.php';
$laravel->make(Kernel::class)->bootstrap();

$source = $argv[1] ?? '';
$includeWorktrees = ($argv[2] ?? 'false') === 'true';
$hostname = $argv[3] ?? null;

if (
    ! str_starts_with($source, '/dev/shm/orb105-')
    && ! str_starts_with($source, '/home/orbit/orb105-')
) {
    fwrite(STDERR, "Invalid ORB-105 registration source.\n");
    exit(64);
}

$profile = $laravel->make(GatewayConfigRepository::class)->active();

if ($profile === null) {
    fwrite(STDERR, "The active Gateway profile is missing.\n");
    exit(64);
}

$connector = $laravel->make(GatewayConnectorFactory::class)->make($profile);

try {
    $response = $connector->send(new RegisterAppInstanceRequest(
        sourcePath: $source,
        appId: 1,
        includeWorktrees: $includeWorktrees,
        hostname: is_string($hostname) && $hostname !== '' ? $hostname : null,
    ))->dto();
    echo json_encode($response->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n";
} catch (GatewayApiException $exception) {
    echo json_encode([
        'error' => [
            'code' => $exception->errorCode(),
            'message' => $exception->getMessage(),
            'request_id' => $exception->requestId(),
        ],
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n";
    exit(1);
}
