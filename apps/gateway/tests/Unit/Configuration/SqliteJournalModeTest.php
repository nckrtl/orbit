<?php

declare(strict_types=1);

use Illuminate\Database\Connectors\SQLiteConnector;
use Illuminate\Database\SQLiteConnection;
use Tests\TestCase;

uses(TestCase::class);

it('defaults the sqlite journal mode to WAL', function (): void {
    expect(config('database.connections.sqlite.journal_mode'))->toBe('WAL')
        ->and(config('database.connections.sqlite.synchronous'))->toBe('NORMAL')
        ->and(config('database.connections.sqlite.transaction_mode'))->toBe('IMMEDIATE')
        ->and(config('database.connections.sqlite.busy_timeout'))->toBe(5_000);
});

it('opens sqlite connections in WAL journal mode', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'orbit-gateway-sqlite-wal-');
    if ($path === false) {
        throw new RuntimeException('Could not create a temporary SQLite path.');
    }

    $settings = [
        ...config('database.connections.sqlite'),
        'database' => $path,
        'url' => null,
    ];
    $pdo = new SQLiteConnector()->connect($settings);
    $connection = new SQLiteConnection($pdo, $path, '', $settings);

    expect($connection->getConfig('journal_mode'))->toBe('WAL')
        ->and(strtolower((string) $connection->selectOne('PRAGMA journal_mode')->journal_mode))->toBe('wal')
        ->and((int) $connection->selectOne('PRAGMA synchronous')->synchronous)->toBe(1);

    $connection->disconnect();
    unlink($path);
    @unlink($path.'-wal');
    @unlink($path.'-shm');
});
