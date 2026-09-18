<?php

declare(strict_types=1);

use App\Actions\DatabaseConnections\AddDatabaseConnectionAction;
use App\Actions\DatabaseConnections\RemoveDatabaseConnectionAction;
use App\Actions\DatabaseConnections\UpdateDatabaseConnectionAction;
use App\Data\DatabaseConnections\AddDatabaseConnectionData;
use App\Data\DatabaseConnections\UpdateDatabaseConnectionData;
use App\Domain\Broadcasting\RecordBroadcast;
use App\Domain\Broadcasting\RecordEventType;
use App\Domain\DatabaseConnections\DatabaseDriver;
use App\Models\DatabaseConnection;
use Illuminate\Support\Facades\Event;

describe('DatabaseConnection record events', function (): void {
    it('broadcasts database.created when a new connection is registered', function (): void {
        Event::fake([RecordBroadcast::class]);

        $data = new AddDatabaseConnectionData(
            slug: 'app',
            driver: DatabaseDriver::Mysql,
            nodeId: null,
            host: 'db.example.test',
            port: null,
            database: 'app',
            path: null,
            username: 'app',
            password: 'secret',
        );

        $connection = new AddDatabaseConnectionAction()->execute($data);

        Event::assertDispatched(
            RecordBroadcast::class,
            fn (RecordBroadcast $event): bool => $event->type === RecordEventType::DatabaseCreated
                && $event->id === $connection->id
                && $event->data['slug'] === 'app',
        );
    });

    it('broadcasts database.updated when a connection field changes', function (): void {
        $connection = DatabaseConnection::query()->create([
            'slug' => 'app',
            'driver' => DatabaseDriver::Mysql,
            'host' => 'db.example.test',
            'database' => 'app',
            'username' => 'app',
            'password' => 'secret',
        ]);

        Event::fake([RecordBroadcast::class]);

        new UpdateDatabaseConnectionAction()->execute($connection, new UpdateDatabaseConnectionData(
            driverProvided: false,
            driver: null,
            nodeIdProvided: false,
            nodeId: null,
            hostProvided: true,
            host: 'db2.example.test',
            portProvided: false,
            port: null,
            databaseProvided: false,
            database: null,
            pathProvided: false,
            path: null,
            usernameProvided: false,
            username: null,
            passwordProvided: false,
            password: null,
        ));

        Event::assertDispatched(
            RecordBroadcast::class,
            fn (RecordBroadcast $event): bool => $event->type === RecordEventType::DatabaseUpdated
                && $event->id === $connection->id
                && $event->data['host'] === 'db2.example.test',
        );
    });

    it('broadcasts database.deleted with a minimal snapshot when a connection is removed', function (): void {
        $connection = DatabaseConnection::query()->create([
            'slug' => 'app',
            'driver' => DatabaseDriver::Mysql,
            'host' => 'db.example.test',
            'database' => 'app',
            'username' => 'app',
            'password' => 'secret',
        ]);

        Event::fake([RecordBroadcast::class]);

        new RemoveDatabaseConnectionAction()->execute($connection);

        Event::assertDispatched(
            RecordBroadcast::class,
            fn (RecordBroadcast $event): bool => $event->type === RecordEventType::DatabaseDeleted
                && $event->id === $connection->id
                && $event->data === ['id' => $connection->id, 'slug' => 'app'],
        );
    });
});
