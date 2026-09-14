<?php

declare(strict_types=1);

use App\Data\GatewayProfile;
use App\Repositories\GatewayConfigRepository;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use Orbit\Sdk\Requests\DatabaseConnections\AddDatabaseConnectionRequest;
use Orbit\Sdk\Requests\DatabaseConnections\AttachDatabaseConnectionRequest;
use Orbit\Sdk\Requests\DatabaseConnections\DetachDatabaseConnectionRequest;
use Orbit\Sdk\Requests\DatabaseConnections\ListDatabaseConnectionsRequest;
use Orbit\Sdk\Requests\DatabaseConnections\RemoveDatabaseConnectionRequest;
use Orbit\Sdk\Requests\DatabaseConnections\ShowDatabaseConnectionRequest;
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

it('adds a mysql connection through the typed request and hides the password', function (): void {
    $mockClient = database_cli_mock(AddDatabaseConnectionRequest::class, database_cli_gateway_data(), status: 201);

    $this
        ->artisan('database:add', [
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
        ->toBeInstanceOf(AddDatabaseConnectionRequest::class)
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

it('attaches a connection through the typed request and hides the password', function (): void {
    $mockClient = database_cli_mock(AttachDatabaseConnectionRequest::class, database_cli_attachment_data());

    $this
        ->artisan('database:attach', [
            'slug' => 'app',
            '--instance' => '12',
            '--json' => true,
        ])
        ->expectsOutput(database_cli_attachment_json())
        ->doesntExpectOutputToContain(DATABASE_CLI_SECRET)
        ->assertExitCode(0);

    $request = $mockClient->getLastRequest();

    expect($request)
        ->toBeInstanceOf(AttachDatabaseConnectionRequest::class)
        ->and($request?->resolveEndpoint())
        ->toBe('/api/v1/instances/12/database-connections/app')
        ->and($request?->body()->all())
        ->toBe([]);
});

it('detaches a connection after --force', function (): void {
    $mockClient = database_cli_mock(DetachDatabaseConnectionRequest::class, [
        ...database_cli_attachment_data(),
        'operation' => 'detach',
        'host' => null,
        'port' => null,
    ]);

    $this
        ->artisan('database:detach', [
            'slug' => 'app',
            '--instance' => 'environment-api.test',
            '--prefix' => 'CACHE_DB',
            '--force' => true,
            '--json' => true,
        ])
        ->doesntExpectOutputToContain(DATABASE_CLI_SECRET)
        ->assertExitCode(0);

    expect($mockClient->getLastRequest())
        ->toBeInstanceOf(DetachDatabaseConnectionRequest::class)
        ->and($mockClient->getLastRequest()?->resolveEndpoint())
        ->toBe('/api/v1/instances/environment-api.test/database-connections/app')
        ->and($mockClient->getLastRequest()?->body()->all())
        ->toBe(['prefix' => 'CACHE_DB']);
});

it('refuses an invalid attach prefix before it contacts the Gateway', function (): void {
    $mock = MockClient::global();

    $this
        ->artisan('database:attach', [
            'slug' => 'app',
            '--instance' => '12',
            '--prefix' => 'db',
            '--json' => true,
        ])
        ->assertExitCode(1);

    expect($mock->getLastPendingRequest())->toBeNull();
});

it('removes a connection after --force', function (): void {
    $mockClient = database_cli_mock(RemoveDatabaseConnectionRequest::class, database_cli_gateway_data());

    $this
        ->artisan('database:remove', ['slug' => 'app', '--force' => true, '--json' => true])
        ->expectsOutput(database_cli_json())
        ->assertExitCode(0);

    expect($mockClient->getLastRequest())
        ->toBeInstanceOf(RemoveDatabaseConnectionRequest::class);
});

it('refuses mysql add without a password before it contacts the Gateway', function (): void {
    $mock = MockClient::global();

    $this
        ->artisan('database:add', [
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
