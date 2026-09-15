<?php

declare(strict_types=1);

use App\Domain\DatabaseConnections\DatabaseDriver;
use App\Infrastructure\Activity\CommandActivityInputSanitizer;
use App\Infrastructure\DatabaseConnections\DatabaseResultRedactor;
use App\Infrastructure\DatabaseConnections\PdoDatabaseInspector;
use App\Models\DatabaseConnection;

it('builds a mysql DSN without embedding the password', function (): void {
    $connection = new DatabaseConnection([
        'slug' => 'app',
        'driver' => DatabaseDriver::Mysql,
        'host' => 'db.example.test',
        'port' => 3306,
        'database' => 'app',
        'username' => 'app',
        'password' => 'db-dsn-secret-11ae',
    ]);
    $inspector = new PdoDatabaseInspector(new DatabaseResultRedactor(new CommandActivityInputSanitizer));

    expect($inspector->dsn($connection))
        ->toBe('mysql:host=db.example.test;port=3306;dbname=app;charset=utf8mb4')
        ->and($inspector->dsn($connection))
        ->not->toContain('db-dsn-secret-11ae');
});
