<?php

declare(strict_types=1);

use App\Data\GatewayProfile;
use App\Repositories\GatewayConfigRepository;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
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
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Symfony\Component\Console\Tester\CommandTester;

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

    [$exit, $output] = database_cli_display('database:list');
    $flat = preg_replace('/[ \t]+/', ' ', $output) ?? $output;
    expect($exit)->toBe(0);
    expect($flat)->toContain('SLUG');
    expect($flat)->toContain('DRIVER');
    expect($flat)->toContain('│ app │ mysql │ db.example.test │ app │ stored │');
    expect($flat)->toContain('Request ID: '.database_cli_request_id());
    expect($output)->not->toContain(DATABASE_CLI_SECRET);
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

it('refuses JSON destruction without --force', function (): void {
    $mock = MockClient::global();

    [$exit, $output] = database_cli_display('database:destroy', ['slug' => 'app', '--json' => true]);
    expect($exit)->toBe(1);
    expect(json_decode(trim($output), true, flags: JSON_THROW_ON_ERROR))->toBe([
        'error' => [
            'code' => 'database.confirmation_required',
            'message' => 'Use --force to confirm Database connection destruction.',
            'request_id' => null,
        ],
    ]);
    expect($mock->getLastPendingRequest())->toBeNull();
});

it('refuses JSON attachment removal without --force', function (): void {
    $mock = MockClient::global();

    [$exit, $output] = database_cli_display('instance:database:remove', [
        'slug' => 'app',
        '--instance' => '12',
        '--json' => true,
    ]);
    expect($exit)->toBe(1);
    expect(json_decode(trim($output), true, flags: JSON_THROW_ON_ERROR))->toBe([
        'error' => [
            'code' => 'database.confirmation_required',
            'message' => 'Use --force to confirm Database connection removal from the AppInstance.',
            'request_id' => null,
        ],
    ]);
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

it('creates a managed MySQL user through the Process-scoped request and hides the password', function (): void {
    $mockClient = database_cli_mock(CreateDatabaseUserRequest::class, [
        ...database_cli_gateway_data(),
        'node_id' => 8,
        'host' => '10.44.0.80',
        'port' => 3307,
    ], status: 201);

    $this
        ->artisan('database:user:create', [
            'slug' => 'app',
            '--process' => '12',
            '--database' => 'app',
            '--username' => 'app',
            '--password' => DATABASE_CLI_SECRET,
            '--json' => true,
        ])
        ->expectsOutput(json_encode(
            [
                ...database_cli_gateway_data(),
                'node_id' => 8,
                'host' => '10.44.0.80',
                'port' => 3307,
                'request_id' => database_cli_request_id(),
            ],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
        ))
        ->doesntExpectOutputToContain(DATABASE_CLI_SECRET)
        ->assertExitCode(0);

    $request = $mockClient->getLastRequest();

    expect($request)
        ->toBeInstanceOf(CreateDatabaseUserRequest::class)
        ->and($request?->resolveEndpoint())
        ->toBe('/api/v1/processes/12/database-users')
        ->and($request?->body()->all())
        ->toBe([
            'slug' => 'app',
            'database' => 'app',
            'username' => 'app',
            'password' => DATABASE_CLI_SECRET,
        ]);
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
});

it('preserves exact query JSON for a write-enabled statement', function (): void {
    $writeClient = database_cli_mock(QueryDatabaseConnectionRequest::class, [
        'slug' => 'app',
        'driver' => 'mysql',
        'write' => true,
        'columns' => [],
        'rows' => [],
        'row_count' => 1,
        'truncated' => false,
    ]);

    [$exit, $output] = database_cli_display('database:query', [
        'slug' => 'app',
        'sql' => 'DELETE FROM users',
        '--write' => true,
        '--json' => true,
    ]);
    expect($exit)->toBe(0);
    expect(json_decode(trim($output), true, flags: JSON_THROW_ON_ERROR))->toBe([
        'slug' => 'app',
        'driver' => 'mysql',
        'write' => true,
        'columns' => [],
        'rows' => [],
        'row_count' => 1,
        'truncated' => false,
        'request_id' => database_cli_request_id(),
    ]);
    expect($writeClient->getLastRequest()?->body()->all())
        ->toBe(['sql' => 'DELETE FROM users', 'write' => true]);
});

it('renders a successful no-rowset write-enabled statement without an empty-table message', function (): void {
    database_cli_mock(QueryDatabaseConnectionRequest::class, [
        'slug' => 'app',
        'driver' => 'mysql',
        'write' => true,
        'columns' => [],
        'rows' => [],
        'row_count' => 0,
        'truncated' => false,
    ]);

    [$exit, $output] = database_cli_display('database:query', [
        'slug' => 'app',
        'sql' => 'DELETE FROM users',
        '--write' => true,
    ]);
    $flat = preg_replace('/[ \t]+/', ' ', $output) ?? $output;
    expect($exit)->toBe(0);
    expect($flat)->toContain('Write permission yes');
    expect($flat)->toContain('Reported row count 0');
    expect($output)->toContain('Statement completed.');
    expect($output)->not->toContain('No matching records found.');
    expect($output)->not->toContain('Wrote');
    expect($output)->not->toContain('Rows affected');
});

it('keeps a SELECT rowset when write permission is admitted', function (): void {
    database_cli_mock(QueryDatabaseConnectionRequest::class, [
        'slug' => 'app',
        'driver' => 'mysql',
        'write' => true,
        'columns' => ['email'],
        'rows' => [['email' => 'owner@example.test']],
        'row_count' => 1,
        'truncated' => false,
    ]);

    [$exit, $output] = database_cli_display('database:query', [
        'slug' => 'app',
        'sql' => 'SELECT email FROM users',
        '--write' => true,
    ]);
    $flat = preg_replace('/[ \t]+/', ' ', $output) ?? $output;
    expect($exit)->toBe(0);
    expect($flat)->toContain('Write permission yes');
    expect($output)->toContain('owner@example.test');
    expect($output)->not->toContain('Statement completed.');
});

it('warns when a read is truncated without inventing omitted totals', function (): void {
    database_cli_mock(QueryDatabaseConnectionRequest::class, [
        'slug' => 'app',
        'driver' => 'mysql',
        'write' => false,
        'columns' => ['email'],
        'rows' => [['email' => 'owner@example.test']],
        'row_count' => 500,
        'truncated' => true,
    ]);

    [$exit, $output] = database_cli_display('database:query', [
        'slug' => 'app',
        'sql' => 'SELECT email FROM users',
    ]);
    $flat = preg_replace('/[ \t]+/', ' ', $output) ?? $output;
    expect($exit)->toBe(0);
    expect($flat)->toContain('Write permission no');
    expect($flat)->toContain('Truncated yes');
    expect($output)->toContain('owner@example.test');
    expect($output)->toContain('Result truncated at the Gateway row limit.');
    expect($output)->toContain('The omitted total is not known.');
});

it('renders query null boolean float and formatter-tag cells literally', function (): void {
    database_cli_mock(QueryDatabaseConnectionRequest::class, [
        'slug' => 'app',
        'driver' => 'mysql',
        'write' => false,
        'columns' => ['flag', 'amount', 'note'],
        'rows' => [[
            'flag' => true,
            'amount' => 1.5,
            'note' => '<info>id</info>',
        ], [
            'flag' => false,
            'amount' => null,
            'note' => 'plain',
        ]],
        'row_count' => 2,
        'truncated' => false,
    ]);

    [$exit, $output] = database_cli_display('database:query', [
        'slug' => 'app',
        'sql' => 'SELECT flag, amount, note FROM users',
    ]);
    expect($exit)->toBe(0);
    expect($output)->toContain('<info>id</info>');
    expect($output)->toContain('1.5');
    expect($output)->toContain('plain');
});

it('lists tables and describes schema through typed inspection requests', function (): void {
    $columns = [
        ['name' => 'id', 'type' => 'int', 'nullable' => false, 'default' => null, 'primary' => true],
    ];
    $client = MockClient::global([
        ListDatabaseTablesRequest::class => MockResponse::make([
            'data' => ['slug' => 'app', 'driver' => 'mysql', 'tables' => ['users']],
            'meta' => ['request_id' => database_cli_request_id()],
        ]),
        ShowDatabaseSchemaRequest::class => MockResponse::make([
            'data' => [
                'slug' => 'app',
                'driver' => 'mysql',
                'tables' => [['name' => 'users', 'columns' => $columns]],
            ],
            'meta' => ['request_id' => database_cli_request_id()],
        ]),
        DescribeDatabaseTableRequest::class => MockResponse::make([
            'data' => ['slug' => 'app', 'driver' => 'mysql', 'table' => 'users', 'columns' => $columns],
            'meta' => ['request_id' => database_cli_request_id()],
        ]),
    ]);

    $this
        ->artisan('database:tables', ['slug' => 'app', '--json' => true])
        ->assertExitCode(0);

    expect($client->getLastRequest())
        ->toBeInstanceOf(ListDatabaseTablesRequest::class)
        ->and($client->getLastRequest()?->resolveEndpoint())
        ->toBe('/api/v1/database-connections/app/tables');

    $this
        ->artisan('database:schema', ['slug' => 'app', '--json' => true])
        ->assertExitCode(0);

    $this
        ->artisan('database:describe', ['slug' => 'app', 'table' => 'users', '--json' => true])
        ->assertExitCode(0);

    expect($client->getLastRequest())
        ->toBeInstanceOf(DescribeDatabaseTableRequest::class)
        ->and($client->getLastRequest()?->resolveEndpoint())
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
/**
 * @param  array<string, mixed>  $arguments
 * @return array{0: int, 1: string}
 */
function database_cli_display(string $command, array $arguments = []): array
{
    $tester = new CommandTester(app(Kernel::class)->all()[$command]);

    return [$tester->execute($arguments, ['interactive' => false]), $tester->getDisplay(true)];
}

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
