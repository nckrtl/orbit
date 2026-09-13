<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;

$root = dirname(__DIR__, 3);

require $root.'/vendor/autoload.php';

$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

echo json_encode([
    'environment' => $app->environment(),
    'database' => $app->make('config')->get('database.connections.sqlite.database'),
    'url' => $app->make('config')->get('database.connections.sqlite.url'),
], JSON_THROW_ON_ERROR);
