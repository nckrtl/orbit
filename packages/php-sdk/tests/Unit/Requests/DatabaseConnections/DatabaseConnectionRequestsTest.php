<?php

declare(strict_types=1);

use Orbit\Sdk\GatewayConnector;
use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Requests\DatabaseConnections\AddInstanceDatabaseRequest;
use Orbit\Sdk\Requests\DatabaseConnections\CreateDatabaseConnectionRequest;
use Orbit\Sdk\Requests\DatabaseConnections\CreateDatabaseUserRequest;
use Orbit\Sdk\Requests\DatabaseConnections\DescribeDatabaseTableRequest;
use Orbit\Sdk\Requests\DatabaseConnections\DestroyDatabaseConnectionRequest;
use Orbit\Sdk\Requests\DatabaseConnections\ListDatabaseConnectionsRequest;
use Orbit\Sdk\Requests\DatabaseConnections\ListDatabaseTablesRequest;
use Orbit\Sdk\Requests\DatabaseConnections\QueryDatabaseConnectionRequest;
use Orbit\Sdk\Requests\DatabaseConnections\RemoveInstanceDatabaseRequest;
use Orbit\Sdk\Requests\DatabaseConnections\ShowDatabaseConnectionRequest;
use Orbit\Sdk\Requests\DatabaseConnections\ShowDatabaseSchemaRequest;
use Orbit\Sdk\Requests\DatabaseConnections\UpdateDatabaseConnectionRequest;
use Orbit\Sdk\Responses\DatabaseConnections\DatabaseConnectionAttachmentResponse;
use Orbit\Sdk\Responses\DatabaseConnections\DatabaseConnectionResponse;
use Orbit\Sdk\Responses\DatabaseConnections\DatabaseConnectionsResponse;
use Orbit\Sdk\Responses\DatabaseConnections\DatabaseDescribeResponse;
use Orbit\Sdk\Responses\DatabaseConnections\DatabaseQueryResponse;
use Orbit\Sdk\Responses\DatabaseConnections\DatabaseSchemaResponse;
use Orbit\Sdk\Responses\DatabaseConnections\DatabaseTablesResponse;
use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

const DATABASE_CONNECTION_SDK_SECRET = 'db-sdk-secret-71ae';

describe('database connection requests', function (): void {
    it('does not keep replaced request class names', function (): void {
        expect(class_exists('Orbit\\Sdk\\Requests\\DatabaseConnections\\AddDatabaseConnectionRequest'))
            ->toBeFalse()
            ->and(class_exists('Orbit\\Sdk\\Requests\\DatabaseConnections\\RemoveDatabaseConnectionRequest'))
            ->toBeFalse()
            ->and(class_exists('Orbit\\Sdk\\Requests\\DatabaseConnections\\AttachDatabaseConnectionRequest'))
            ->toBeFalse()
            ->and(class_exists('Orbit\\Sdk\\Requests\\DatabaseConnections\\DetachDatabaseConnectionRequest'))
            ->toBeFalse();
    });

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
        'create' => [new CreateDatabaseConnectionRequest('app', 'mysql'), Method::POST, '/api/v1/database-connections'],
        'user-create' => [new CreateDatabaseUserRequest(12, 'app', 'app', 'app', 'secret'), Method::POST, '/api/v1/processes/12/database-users'],
        'update' => [new UpdateDatabaseConnectionRequest('app'), Method::PATCH, '/api/v1/database-connections/app'],
        'destroy' => [new DestroyDatabaseConnectionRequest('app'), Method::DELETE, '/api/v1/database-connections/app'],
        'add' => [new AddInstanceDatabaseRequest(12, 'app'), Method::PUT, '/api/v1/instances/12/database-connections/app'],
        'remove' => [new RemoveInstanceDatabaseRequest('app.test', 'app'), Method::DELETE, '/api/v1/instances/app.test/database-connections/app'],
        'query' => [new QueryDatabaseConnectionRequest('app', 'SELECT 1'), Method::POST, '/api/v1/database-connections/app/query'],
        'tables' => [new ListDatabaseTablesRequest('app'), Method::GET, '/api/v1/database-connections/app/tables'],
        'schema' => [new ShowDatabaseSchemaRequest('app'), Method::GET, '/api/v1/database-connections/app/schema'],
        'describe' => [new DescribeDatabaseTableRequest('app', 'users'), Method::GET, '/api/v1/database-connections/app/describe/users'],
    ]);

    it('encodes slugs in item paths', function (): void {
        expect(new ShowDatabaseConnectionRequest('app-db')->resolveEndpoint())
            ->toBe('/api/v1/database-connections/app-db');
    });

    it('sends the managed user payload on the Process path', function (): void {
        expect(new CreateDatabaseUserRequest(
            processId: 12,
            slug: 'app',
            database: 'app',
            username: 'app',
            password: DATABASE_CONNECTION_SDK_SECRET,
        )->body()->all())
            ->toBe([
                'slug' => 'app',
                'database' => 'app',
                'username' => 'app',
                'password' => DATABASE_CONNECTION_SDK_SECRET,
            ]);
    });

    it('omits null create fields and preserves an explicit empty password', function (): void {
        expect(new CreateDatabaseConnectionRequest('local', 'sqlite', path: '/tmp/app.sqlite')->body()->all())
            ->toBe([
                'slug' => 'local',
                'driver' => 'sqlite',
                'path' => '/tmp/app.sqlite',
            ])
            ->and(new CreateDatabaseConnectionRequest(
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

    it('omits an absent add prefix and encodes hostname selectors', function (): void {
        expect(new AddInstanceDatabaseRequest(12, 'app')->body()->all())
            ->toBe([])
            ->and(new AddInstanceDatabaseRequest('app.test', 'app-db', 'CACHE_DB')->body()->all())
            ->toBe(['prefix' => 'CACHE_DB'])
            ->and(new AddInstanceDatabaseRequest('app.test', 'app-db')->resolveEndpoint())
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
            AddInstanceDatabaseRequest::class => MockResponse::make([
                'data' => $payload,
                'meta' => ['request_id' => $requestId],
            ]),
        ]);
        $connector = new GatewayConnector('https://10.44.0.1');
        $connector->withMockClient($mockClient);

        $attached = $connector->send(new AddInstanceDatabaseRequest(12, 'app'))->dto();

        expect($attached)
            ->toBeInstanceOf(DatabaseConnectionAttachmentResponse::class)
            ->and($attached->toArray())
            ->toBe([...$payload, 'request_id' => $requestId])
            ->and(print_r($attached, true))
            ->not->toContain('password');
    });

    it('keeps list show destroy and inspection reads bodyless', function (GatewayRequest $request): void {
        expect($request)->not->toBeInstanceOf(HasBody::class);
    })->with([
        'list' => [new ListDatabaseConnectionsRequest],
        'show' => [new ShowDatabaseConnectionRequest('app')],
        'destroy' => [new DestroyDatabaseConnectionRequest('app')],
        'tables' => [new ListDatabaseTablesRequest('app')],
        'schema' => [new ShowDatabaseSchemaRequest('app')],
        'describe' => [new DescribeDatabaseTableRequest('app', 'users')],
    ]);

    it('sends query SQL and the write flag', function (): void {
        expect(new QueryDatabaseConnectionRequest('app', 'SELECT 1')->body()->all())
            ->toBe(['sql' => 'SELECT 1', 'write' => false])
            ->and(new QueryDatabaseConnectionRequest('app', 'DELETE FROM users', true)->body()->all())
            ->toBe(['sql' => 'DELETE FROM users', 'write' => true]);
    });

    it('maps inspection envelopes and redacts credential-shaped cells', function (): void {
        $requestId = '11111111-1111-4111-8111-111111111111';
        $mockClient = new MockClient([
            QueryDatabaseConnectionRequest::class => MockResponse::make([
                'data' => [
                    'slug' => 'app',
                    'driver' => 'mysql',
                    'write' => false,
                    'columns' => ['email'],
                    'rows' => [['email' => 'password='.DATABASE_CONNECTION_SDK_SECRET]],
                    'row_count' => 1,
                    'truncated' => false,
                ],
                'meta' => ['request_id' => $requestId],
            ]),
            ListDatabaseTablesRequest::class => MockResponse::make([
                'data' => ['slug' => 'app', 'driver' => 'mysql', 'tables' => ['users']],
                'meta' => ['request_id' => $requestId],
            ]),
            ShowDatabaseSchemaRequest::class => MockResponse::make([
                'data' => [
                    'slug' => 'app',
                    'driver' => 'mysql',
                    'tables' => [[
                        'name' => 'users',
                        'columns' => [
                            ['name' => 'id', 'type' => 'int', 'nullable' => false, 'default' => null, 'primary' => true],
                        ],
                    ]],
                ],
                'meta' => ['request_id' => $requestId],
            ]),
            DescribeDatabaseTableRequest::class => MockResponse::make([
                'data' => [
                    'slug' => 'app',
                    'driver' => 'mysql',
                    'table' => 'users',
                    'columns' => [
                        ['name' => 'id', 'type' => 'int', 'nullable' => false, 'default' => null, 'primary' => true],
                    ],
                ],
                'meta' => ['request_id' => $requestId],
            ]),
        ]);
        $connector = new GatewayConnector('https://10.44.0.1');
        $connector->withMockClient($mockClient);

        $query = $connector->send(new QueryDatabaseConnectionRequest('app', 'SELECT 1'))->dto();
        $tables = $connector->send(new ListDatabaseTablesRequest('app'))->dto();
        $schema = $connector->send(new ShowDatabaseSchemaRequest('app'))->dto();
        $describe = $connector->send(new DescribeDatabaseTableRequest('app', 'users'))->dto();

        expect($query)
            ->toBeInstanceOf(DatabaseQueryResponse::class)
            ->and($query->rows[0]['email'] ?? null)
            ->not->toContain(DATABASE_CONNECTION_SDK_SECRET)
            ->and($tables)
            ->toBeInstanceOf(DatabaseTablesResponse::class)
            ->and($schema)
            ->toBeInstanceOf(DatabaseSchemaResponse::class)
            ->and($describe)
            ->toBeInstanceOf(DatabaseDescribeResponse::class)
            ->and($describe->table)
            ->toBe('users');
    });

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
