<?php

declare(strict_types=1);

use App\Domain\DatabaseConnections\DatabaseConnectionEnvProjection;
use App\Domain\DatabaseConnections\DatabaseDriver;
use App\Models\AppInstance;
use App\Models\DatabaseConnection;

it('names drifted and leftover keys without returning stored values', function (): void {
    $projection = new DatabaseConnectionEnvProjection;
    $connection = new DatabaseConnection([
        'driver' => DatabaseDriver::Sqlite,
        'path' => '/var/lib/app/database.sqlite',
    ]);
    $instance = new AppInstance(['node_id' => 1]);
    $projected = $projection->project($connection, $instance, 'DB');

    expect($projection->driftedKeys([
        'DB_CONNECTION' => 'sqlite',
        'DB_DATABASE' => '/tmp/wrong.sqlite',
        'DB_HOST' => 'db.example.test',
        'DB_PASSWORD' => 'secret-must-not-appear',
    ], $projected))
        ->toBe(['DB_DATABASE', 'DB_HOST', 'DB_PASSWORD'])
        ->and(json_encode($projected['keys'], JSON_THROW_ON_ERROR))
        ->not->toContain('secret-must-not-appear');
});
