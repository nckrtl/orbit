<?php

declare(strict_types=1);

use App\Domain\DatabaseConnections\DatabaseDriver;
use App\Infrastructure\Activity\CommandActivityInputSanitizer;
use App\Infrastructure\DatabaseConnections\DatabaseResultRedactor;
use App\Infrastructure\DatabaseConnections\PdoDatabaseInspector;
use App\Models\DatabaseConnection;

it('builds driver DSNs without embedding the password', function (DatabaseDriver $driver, string $dsn): void {
    $connection = new DatabaseConnection([
        'slug' => 'app',
        'driver' => $driver,
        'host' => 'db.example.test',
        'port' => $driver->defaultPort(),
        'database' => 'app',
        'path' => '/var/lib/app/database.sqlite',
        'username' => 'app',
    ]);
    $inspector = new PdoDatabaseInspector(new DatabaseResultRedactor(new CommandActivityInputSanitizer));

    expect($inspector->dsn($connection))
        ->toBe($dsn)
        ->and($inspector->dsn($connection))
        ->not->toContain('password');
})->with([
    'mysql' => [DatabaseDriver::Mysql, 'mysql:host=db.example.test;port=3306;dbname=app;charset=utf8mb4'],
    'pgsql' => [DatabaseDriver::Pgsql, 'pgsql:host=db.example.test;port=5432;dbname=app'],
    'sqlite' => [DatabaseDriver::Sqlite, 'sqlite:/var/lib/app/database.sqlite'],
]);

it('queries tables and describes columns on a local sqlite file through PDO', function (): void {
    $path = sys_get_temp_dir().'/orbit-pdo-inspect-'.bin2hex(random_bytes(8)).'.sqlite';
    $pdo = new PDO('sqlite:'.$path);
    $pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, email TEXT NOT NULL)');
    $pdo->exec("INSERT INTO users (email) VALUES ('owner@example.test')");

    $connection = new DatabaseConnection([
        'slug' => 'local',
        'driver' => DatabaseDriver::Sqlite,
        'path' => $path,
    ]);
    $inspector = new PdoDatabaseInspector(new DatabaseResultRedactor(new CommandActivityInputSanitizer));

    $query = $inspector->query($connection, 'SELECT email FROM users', false);
    $tables = $inspector->tables($connection);
    $columns = $inspector->describe($connection, 'users');

    expect($query->columns)
        ->toBe(['email'])
        ->and($query->rows)
        ->toBe([['email' => 'owner@example.test']])
        ->and($tables)
        ->toBe(['users'])
        ->and($columns[0]->name)
        ->toBe('id')
        ->and($columns[0]->primary)
        ->toBeTrue();

    unlink($path);
});
