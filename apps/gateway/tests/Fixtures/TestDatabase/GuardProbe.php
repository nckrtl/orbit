<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Tests\Support\TestDatabaseGuard;

require dirname(__DIR__, 2).'/bootstrap.php';

$app = require Application::inferBasePath().'/bootstrap/app.php';
TestDatabaseGuard::register($app);
$app->make(Kernel::class)->bootstrap();

$marker = getenv('ORBIT_TEST_DATABASE_PROBE');

if (is_string($marker) && $marker !== '') {
    $connection = $app->make('db')->connection();
    $connection->statement('CREATE TABLE IF NOT EXISTS database_probe (id INTEGER PRIMARY KEY)');
    file_put_contents($marker, json_encode([
        'driver' => $connection->getDriverName(),
        'database' => $connection->getDatabaseName(),
    ], JSON_THROW_ON_ERROR));
}
