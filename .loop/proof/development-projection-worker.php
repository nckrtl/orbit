<?php

declare(strict_types=1);

use App\Domain\AppInstances\DevelopmentAppInstanceProvisioner;
use App\Models\AppInstance;

require '/home/orbit/orbit/apps/gateway/vendor/autoload.php';
$laravel = require '/home/orbit/orbit/apps/gateway/bootstrap/app.php';
$laravel->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$name = $argv[1] ?? '';

if (! preg_match('/^orb173-(same|different)-[ab]$/', $name)) {
    exit(64);
}

$instance = AppInstance::query()->where('name', $name)->with('routes')->sole();
$route = $instance->routes->sole();
$started = hrtime(true);

try {
    $result = app(DevelopmentAppInstanceProvisioner::class)->complete($instance, $route->hostname);
    $duration = (hrtime(true) - $started) / 1_000_000_000;
    $route = $result->routes()->sole();

    echo json_encode([
        'name' => $name,
        'duration_seconds' => round($duration, 6),
        'instance_status' => $result->status->value,
        'route_status' => $route->status->value,
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), PHP_EOL;
} catch (Throwable $exception) {
    $duration = (hrtime(true) - $started) / 1_000_000_000;
    fwrite(STDERR, json_encode([
        'name' => $name,
        'duration_seconds' => round($duration, 6),
        'exception' => $exception::class,
        'message' => $exception->getMessage(),
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES).PHP_EOL);
    exit(1);
}
