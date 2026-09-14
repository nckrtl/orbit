<?php

declare(strict_types=1);

use Orbit\Sdk\GatewayConnector;
use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Requests\DatabaseConnections\AddDatabaseConnectionRequest;
use Orbit\Sdk\Requests\DatabaseConnections\AttachDatabaseConnectionRequest;
use Orbit\Sdk\Requests\DatabaseConnections\DetachDatabaseConnectionRequest;
use Orbit\Sdk\Requests\DatabaseConnections\ListDatabaseConnectionsRequest;
use Orbit\Sdk\Requests\DatabaseConnections\RemoveDatabaseConnectionRequest;
use Orbit\Sdk\Requests\DatabaseConnections\ShowDatabaseConnectionRequest;
use Orbit\Sdk\Requests\DatabaseConnections\UpdateDatabaseConnectionRequest;
use Orbit\Sdk\Responses\DatabaseConnections\DatabaseConnectionAttachmentResponse;
use Orbit\Sdk\Responses\DatabaseConnections\DatabaseConnectionResponse;
use Orbit\Sdk\Responses\DatabaseConnections\DatabaseConnectionsResponse;
use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

const DATABASE_CONNECTION_SDK_SECRET = 'db-sdk-secret-71ae';

describe('database connection requests', function (): void {
    it('uses the exact registry and attachment methods and endpoints', function (
        GatewayRequest $request,
        Method $method,
        string $endpoint,
    ): void {
        expect($request->getMethod())
            ->toBe($method)
            ->and($request->resolveEndpoint())
            ->toBe($endpoint);
    })->with([
        'list' => [new ListDatabaseConnectionsRequest, Method::GET, '/api/v1/database-connections'],
        'show' => [new ShowDatabaseConnectionRequest('app'), Method::GET, '/api/v1/database-connections/app'],
        'add' => [new AddDatabaseConnectionRequest('app', 'mysql'), Method::POST, '/api/v1/database-connections'],
        'update' => [new UpdateDatabaseConnectionRequest('app'), Method::PATCH, '/api/v1/database-connections/app'],
        'remove' => [new RemoveDatabaseConnectionRequest('app'), Method::DELETE, '/api/v1/database-connections/app'],
        'attach' => [new AttachDatabaseConnectionRequest(12, 'app'), Method::PUT, '/api/v1/instances/12/database-connections/app'],
        'detach' => [new DetachDatabaseConnectionRequest('app.test', 'app'), Method::DELETE, '/api/v1/instances/app.test/database-connections/app'],
    ]);

    it('encodes slugs in item paths', function (): void {
        expect(new ShowDatabaseConnectionRequest('app-db')->resolveEndpoint())
            ->toBe('/api/v1/database-connections/app-db');
    });

    it('omits null add fields and preserves an explicit empty password', function (): void {
        expect(new AddDatabaseConnectionRequest('local', 'sqlite', path: '/tmp/app.sqlite')->body()->all())
            ->toBe([
                'slug' => 'local',
                'driver' => 'sqlite',
                'path' => '/tmp/app.sqlite',
            ])
            ->and(new AddDatabaseConnectionRequest(
                slug: 'app',
                driver: 'mysql',
                host: 'db.example.test',
                database: 'app',
                username: 'app',
                password: '',
                hasPassword: true,
            )->body()->all())
            ->toBe([
                'slug' => 'app',
                'driver' => 'mysql',
                'host' => 'db.example.test',
                'database' => 'app',
                'username' => 'app',
                'password' => '',
            ]);
    });

    it('sends only supplied update fields', function (): void {
        expect(new UpdateDatabaseConnectionRequest(
            slug: 'app',
            hasHost: true,
            host: 'db-internal.example.test',
            hasPassword: true,
            password: DATABASE_CONNECTION_SDK_SECRET,
        )->body()->all())
            ->toBe([
                'host' => 'db-internal.example.test',
                'password' => DATABASE_CONNECTION_SDK_SECRET,
            ]);
    });

    it('omits an absent attach prefix and encodes hostname selectors', function (): void {
        expect(new AttachDatabaseConnectionRequest(12, 'app')->body()->all())
            ->toBe([])
            ->and(new AttachDatabaseConnectionRequest('app.test', 'app-db', 'CACHE_DB')->body()->all())
            ->toBe(['prefix' => 'CACHE_DB'])
            ->and(new AttachDatabaseConnectionRequest('app.test', 'app-db')->resolveEndpoint())
            ->toBe('/api/v1/instances/app.test/database-connections/app-db');
    });

    it('maps attachment envelopes without exposing a password', function (): void {
        $requestId = '11111111-1111-4111-8111-111111111111';
        $payload = [
            'app_instance_id' => 12,
            'slug' => 'app',
            'prefix' => 'DB',
            'keys' => ['DB_CONNECTION', 'DB_HOST'],
            'host' => '127.0.0.1',
            'port' => 3307,
            'operation' => 'attach',
            'changed' => true,
            'key_count' => 6,
        ];
        $mockClient = new MockClient([
            AttachDatabaseConnectionRequest::class => MockResponse::make([
                'data' => $payload,
                'meta' => ['request_id' => $requestId],
            ]),
        ]);
        $connector = new GatewayConnector('https://10.44.0.1');
        $connector->withMockClient($mockClient);

        $attached = $connector->send(new AttachDatabaseConnectionRequest(12, 'app'))->dto();

        expect($attached)
            ->toBeInstanceOf(DatabaseConnectionAttachmentResponse::class)
            ->and($attached->toArray())
            ->toBe([...$payload, 'request_id' => $requestId])
            ->and(print_r($attached, true))
            ->not->toContain('password');
    });

    it('keeps list show and remove requests bodyless', function (GatewayRequest $request): void {
        expect($request)->not->toBeInstanceOf(HasBody::class);
    })->with([
        'list' => [new ListDatabaseConnectionsRequest],
        'show' => [new ShowDatabaseConnectionRequest('app')],
        'remove' => [new RemoveDatabaseConnectionRequest('app')],
    ]);

    it('maps item and collection envelopes without exposing a password', function (): void {
        $requestId = '11111111-1111-4111-8111-111111111111';
        $payload = database_connection_sdk_data();
        $mockClient = new MockClient([
            ShowDatabaseConnectionRequest::class => MockResponse::make([
                'data' => $payload,
                'meta' => ['request_id' => $requestId],
            ]),
            ListDatabaseConnectionsRequest::class => MockResponse::make([
                'data' => [$payload],
                'meta' => ['request_id' => $requestId],
            ]),
        ]);
        $connector = new GatewayConnector('https://10.44.0.1');
        $connector->withMockClient($mockClient);

        $shown = $connector->send(new ShowDatabaseConnectionRequest('app'))->dto();
        $listed = $connector->send(new ListDatabaseConnectionsRequest)->dto();

        expect($shown)
            ->toBeInstanceOf(DatabaseConnectionResponse::class)
            ->and($shown->toArray())
            ->toBe([...$payload, 'request_id' => $requestId])
            ->and($listed)
            ->toBeInstanceOf(DatabaseConnectionsResponse::class)
            ->and($listed->toArray())
            ->toBe([
                'connections' => [$payload],
                'request_id' => $requestId,
            ])
            ->and(json_encode($shown->toArray(), JSON_THROW_ON_ERROR))
            ->not->toContain(DATABASE_CONNECTION_SDK_SECRET)
            ->and(print_r($shown, true))
            ->not->toContain(DATABASE_CONNECTION_SDK_SECRET);
    });

    it('redacts credential-shaped host values from responses', function (): void {
        $requestId = '11111111-1111-4111-8111-111111111111';
        $mockClient = new MockClient([
            ShowDatabaseConnectionRequest::class => MockResponse::make([
                'data' => [
                    ...database_connection_sdk_data(),
                    'host' => 'password='.DATABASE_CONNECTION_SDK_SECRET,
                ],
                'meta' => ['request_id' => $requestId],
            ]),
        ]);
        $connector = new GatewayConnector('https://10.44.0.1');
        $connector->withMockClient($mockClient);

        $response = $connector->send(new ShowDatabaseConnectionRequest('app'))->dto();

        expect($response->host)
            ->not->toContain(DATABASE_CONNECTION_SDK_SECRET)
            ->and($response->toArray())
            ->not->toContain(DATABASE_CONNECTION_SDK_SECRET);
    });
});

/** @return array<string, bool|int|string|null> */
function database_connection_sdk_data(): array
{
    return [
        'id' => 4,
        'slug' => 'app',
        'driver' => 'mysql',
        'node_id' => null,
        'host' => 'db.example.test',
        'port' => 3306,
        'database' => 'app',
        'path' => null,
        'username' => 'app',
        'has_password' => true,
    ];
}
