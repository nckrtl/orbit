<?php

declare(strict_types=1);

use App\Data\GatewayProfile;
use App\Repositories\GatewayConfigRepository;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use Orbit\Sdk\Requests\DatabaseConnections\AddInstanceDatabaseRequest;
use Orbit\Sdk\Requests\DatabaseConnections\CreateDatabaseConnectionRequest;
use Orbit\Sdk\Requests\DatabaseConnections\DescribeDatabaseTableRequest;
use Orbit\Sdk\Requests\DatabaseConnections\DestroyDatabaseConnectionRequest;
use Orbit\Sdk\Requests\DatabaseConnections\ListDatabaseConnectionsRequest;
use Orbit\Sdk\Requests\DatabaseConnections\ListDatabaseTablesRequest;
use Orbit\Sdk\Requests\DatabaseConnections\QueryDatabaseConnectionRequest;
use Orbit\Sdk\Requests\DatabaseConnections\RemoveInstanceDatabaseRequest;
use Orbit\Sdk\Requests\DatabaseConnections\ShowDatabaseConnectionRequest;
use Orbit\Sdk\Requests\DatabaseConnections\ShowDatabaseSchemaRequest;
use Orbit\Sdk\Requests\DatabaseConnections\UpdateDatabaseConnectionRequest;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

const DATABASE_CLI_SECRET = 'db-cli-secret-44c1';

beforeEach(function (): void {
    MockClient::destroyGlobal();
    $this->orbitHome = sys_get_temp_dir().'/orbit-cli-database-'.Str::uuid();
    config()->set('orbit.home', $this->orbitHome);

    app(GatewayConfigRepository::class)->add(new GatewayProfile(
        name: 'test',
        url: 'https://10.44.0.1',
        caPath: '/home/orbit/.orbit/ca/root.pem',
    ));
});

afterEach(function (): void {
    MockClient::destroyGlobal();
    new Filesystem()->deleteDirectory($this->orbitHome);
});

it('creates a mysql connection through the typed request and hides the password', function (): void {
    $mockClient = database_cli_mock(CreateDatabaseConnectionRequest::class, database_cli_gateway_data(), status: 201);

    $this
        ->artisan('database:create', [
            'slug' => 'app',
            '--driver' => 'mysql',
            '--host' => 'db.example.test',
            '--database' => 'app',
            '--username' => 'app',
            '--password' => DATABASE_CLI_SECRET,
            '--json' => true,
        ])
        ->expectsOutput(database_cli_json())
        ->doesntExpectOutputToContain(DATABASE_CLI_SECRET)
        ->assertExitCode(0);

    $request = $mockClient->getLastRequest();

    expect($request)
        ->toBeInstanceOf(CreateDatabaseConnectionRequest::class)
        ->and($request?->body()->all())
        ->toBe([
            'slug' => 'app',
            'driver' => 'mysql',
            'host' => 'db.example.test',
            'database' => 'app',
            'username' => 'app',
            'password' => DATABASE_CLI_SECRET,
        ]);
});

it('lists connections with deterministic human output', function (): void {
    database_cli_mock(ListDatabaseConnectionsRequest::class, [database_cli_gateway_data()]);

    $this
        ->artisan('database:list')
        ->expectsTable(
            ['Slug', 'Driver', 'Endpoint', 'Database', 'Password'],
            [['app', 'mysql', 'db.example.test', 'app', 'stored']],
        )
        ->expectsOutput('Request ID: '.database_cli_request_id())
        ->doesntExpectOutputToContain(DATABASE_CLI_SECRET)
        ->assertExitCode(0);
});

it('shows a connection by slug', function (): void {
    $mockClient = database_cli_mock(ShowDatabaseConnectionRequest::class, database_cli_gateway_data());

    $this
        ->artisan('database:show', ['slug' => 'app', '--json' => true])
        ->expectsOutput(database_cli_json())
        ->doesntExpectOutputToContain(DATABASE_CLI_SECRET)
        ->assertExitCode(0);

    expect($mockClient->getLastRequest())
        ->toBeInstanceOf(ShowDatabaseConnectionRequest::class)
        ->and($mockClient->getLastRequest()?->resolveEndpoint())
        ->toBe('/api/v1/database-connections/app');
});

it('updates supplied fields and requires at least one option', function (): void {
    $mockClient = database_cli_mock(UpdateDatabaseConnectionRequest::class, [
        ...database_cli_gateway_data(),
        'host' => 'db-internal.example.test',
    ]);

    $this
        ->artisan('database:update', [
            'slug' => 'app',
            '--host' => 'db-internal.example.test',
            '--json' => true,
        ])
        ->assertExitCode(0);

    expect($mockClient->getLastRequest()?->body()->all())
        ->toBe(['host' => 'db-internal.example.test']);

    $this
        ->artisan('database:update', ['slug' => 'app', '--json' => true])
        ->assertExitCode(1);
});

it('adds a connection on an AppInstance through the typed request and hides the password', function (): void {
    $mockClient = database_cli_mock(AddInstanceDatabaseRequest::class, database_cli_attachment_data());

    $this
        ->artisan('instance:database:add', [
            'slug' => 'app',
            '--instance' => '12',
            '--json' => true,
        ])
        ->expectsOutput(database_cli_attachment_json())
        ->doesntExpectOutputToContain(DATABASE_CLI_SECRET)
        ->assertExitCode(0);

    $request = $mockClient->getLastRequest();

    expect($request)
        ->toBeInstanceOf(AddInstanceDatabaseRequest::class)
        ->and($request?->resolveEndpoint())
        ->toBe('/api/v1/instances/12/database-connections/app')
        ->and($request?->body()->all())
        ->toBe([]);
});

it('removes a connection from an AppInstance after --force', function (): void {
    $mockClient = database_cli_mock(RemoveInstanceDatabaseRequest::class, [
        ...database_cli_attachment_data(),
        'operation' => 'detach',
        'host' => null,
        'port' => null,
    ]);

    $this
        ->artisan('instance:database:remove', [
            'slug' => 'app',
            '--instance' => 'environment-api.test',
            '--prefix' => 'CACHE_DB',
            '--force' => true,
            '--json' => true,
        ])
        ->doesntExpectOutputToContain(DATABASE_CLI_SECRET)
        ->assertExitCode(0);

    expect($mockClient->getLastRequest())
        ->toBeInstanceOf(RemoveInstanceDatabaseRequest::class)
        ->and($mockClient->getLastRequest()?->resolveEndpoint())
        ->toBe('/api/v1/instances/environment-api.test/database-connections/app')
        ->and($mockClient->getLastRequest()?->body()->all())
        ->toBe(['prefix' => 'CACHE_DB']);
});

it('refuses an invalid add prefix before it contacts the Gateway', function (): void {
    $mock = MockClient::global();

    $this
        ->artisan('instance:database:add', [
            'slug' => 'app',
            '--instance' => '12',
            '--prefix' => 'db',
            '--json' => true,
        ])
        ->assertExitCode(1);

    expect($mock->getLastPendingRequest())->toBeNull();
});

it('destroys a connection after --force', function (): void {
    $mockClient = database_cli_mock(DestroyDatabaseConnectionRequest::class, database_cli_gateway_data());

    $this
        ->artisan('database:destroy', ['slug' => 'app', '--force' => true, '--json' => true])
        ->expectsOutput(database_cli_json())
        ->assertExitCode(0);

    expect($mockClient->getLastRequest())
        ->toBeInstanceOf(DestroyDatabaseConnectionRequest::class);
});

it('queries a registered connection and sends the write flag only when asked', function (): void {
    $mockClient = database_cli_mock(QueryDatabaseConnectionRequest::class, [
        'slug' => 'app',
        'driver' => 'mysql',
        'write' => false,
        'columns' => ['email'],
        'rows' => [['email' => 'owner@example.test']],
        'row_count' => 1,
        'truncated' => false,
    ]);

    $this
        ->artisan('database:query', [
            'slug' => 'app',
            'sql' => 'SELECT email FROM users',
            '--json' => true,
        ])
        ->doesntExpectOutputToContain(DATABASE_CLI_SECRET)
        ->assertExitCode(0);

    expect($mockClient->getLastRequest())
        ->toBeInstanceOf(QueryDatabaseConnectionRequest::class)
        ->and($mockClient->getLastRequest()?->resolveEndpoint())
        ->toBe('/api/v1/database-connections/app/query')
        ->and($mockClient->getLastRequest()?->body()->all())
        ->toBe(['sql' => 'SELECT email FROM users', 'write' => false]);

    $writeClient = database_cli_mock(QueryDatabaseConnectionRequest::class, [
        'slug' => 'app',
        'driver' => 'mysql',
        'write' => true,
        'columns' => [],
        'rows' => [],
        'row_count' => 1,
        'truncated' => false,
    ]);

    $this
        ->artisan('database:query', [
            'slug' => 'app',
            'sql' => 'DELETE FROM users',
            '--write' => true,
            '--json' => true,
        ])
        ->assertExitCode(0);

    expect($writeClient->getLastRequest()?->body()->all())
        ->toBe(['sql' => 'DELETE FROM users', 'write' => true]);
});

it('lists tables and describes schema through typed inspection requests', function (): void {
    $tables = database_cli_mock(ListDatabaseTablesRequest::class, [
        'slug' => 'app',
        'driver' => 'mysql',
        'tables' => ['users'],
    ]);

    $this
        ->artisan('database:tables', ['slug' => 'app', '--json' => true])
        ->assertExitCode(0);

    expect($tables->getLastRequest())
        ->toBeInstanceOf(ListDatabaseTablesRequest::class)
        ->and($tables->getLastRequest()?->resolveEndpoint())
        ->toBe('/api/v1/database-connections/app/tables');

    database_cli_mock(ShowDatabaseSchemaRequest::class, [
        'slug' => 'app',
        'driver' => 'mysql',
        'tables' => [[
            'name' => 'users',
            'columns' => [
                ['name' => 'id', 'type' => 'int', 'nullable' => false, 'default' => null, 'primary' => true],
            ],
        ]],
    ]);

    $this
        ->artisan('database:schema', ['slug' => 'app', '--json' => true])
        ->assertExitCode(0);

    $describe = database_cli_mock(DescribeDatabaseTableRequest::class, [
        'slug' => 'app',
        'driver' => 'mysql',
        'table' => 'users',
        'columns' => [
            ['name' => 'id', 'type' => 'int', 'nullable' => false, 'default' => null, 'primary' => true],
        ],
    ]);

    $this
        ->artisan('database:describe', ['slug' => 'app', 'table' => 'users', '--json' => true])
        ->assertExitCode(0);

    expect($describe->getLastRequest())
        ->toBeInstanceOf(DescribeDatabaseTableRequest::class)
        ->and($describe->getLastRequest()?->resolveEndpoint())
        ->toBe('/api/v1/database-connections/app/describe/users');
});

it('refuses an invalid describe table before it contacts the Gateway', function (): void {
    $mock = MockClient::global();

    $this
        ->artisan('database:describe', ['slug' => 'app', 'table' => 'users;drop', '--json' => true])
        ->assertExitCode(1);

    expect($mock->getLastPendingRequest())->toBeNull();
});

it('refuses mysql create without a password before it contacts the Gateway', function (): void {
    $mock = MockClient::global();

    $this
        ->artisan('database:create', [
            'slug' => 'app',
            '--driver' => 'mysql',
            '--host' => 'db.example.test',
            '--database' => 'app',
            '--username' => 'app',
            '--json' => true,
        ])
        ->assertExitCode(1);

    expect($mock->getLastPendingRequest())->toBeNull();
});

/**
 * @param  array<string, mixed>|list<array<string, mixed>>  $data
 */
function database_cli_mock(string $request, array $data, int $status = 200): MockClient
{
    return MockClient::global([
        $request => MockResponse::make([
            'data' => $data,
            'meta' => ['request_id' => database_cli_request_id()],
        ], $status),
    ]);
}

/** @return array<string, bool|int|string|null> */
function database_cli_gateway_data(): array
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

function database_cli_json(): string
{
    return json_encode(
        [...database_cli_gateway_data(), 'request_id' => database_cli_request_id()],
        JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
    );
}

function database_cli_request_id(): string
{
    return '11111111-1111-4111-8111-111111111111';
}

/** @return array<string, array<int, string>|bool|int|string|null> */
function database_cli_attachment_data(): array
{
    return [
        'app_instance_id' => 12,
        'slug' => 'app',
        'prefix' => 'DB',
        'keys' => ['DB_CONNECTION', 'DB_DATABASE', 'DB_HOST', 'DB_PASSWORD', 'DB_PORT', 'DB_USERNAME'],
        'host' => 'db.example.test',
        'port' => 3306,
        'operation' => 'attach',
        'changed' => true,
        'key_count' => 6,
    ];
}

function database_cli_attachment_json(): string
{
    return json_encode(
        [...database_cli_attachment_data(), 'request_id' => database_cli_request_id()],
        JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
    );
}
