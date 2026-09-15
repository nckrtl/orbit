<?php

declare(strict_types=1);

use App\Domain\DatabaseConnections\DatabaseInspectionExecutor;
use App\Domain\DatabaseConnections\DatabaseQueryResult;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Ssh\SshConnection;
use App\Infrastructure\Ssh\SshExecutor;
use App\Models\Activity;
use App\Models\DatabaseConnection;
use App\Models\Node;
use Tests\Support\FakeDatabaseInspectionExecutor;

const DATABASE_INSPECTION_SECRET = 'db-query-secret-6c2e';

beforeEach(function (): void {
    $node = Node::query()->create([
        'name' => 'gateway',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.10',
        'public_ssh_port' => 22,
        'user' => 'orbit',
        'tld' => 'orbit',
        'wireguard_ip' => '10.44.0.1',
    ]);
    $this->node = $this->markAsGateway($node);
    $this->withServerVariables(['REMOTE_ADDR' => $this->node->wireguard_ip]);
});

it('queries a registered mysql connection as read-only by default and redacts audit input', function (): void {
    $this->postJson('/api/v1/database-connections', [
        'slug' => 'app',
        'driver' => 'mysql',
        'host' => 'db.example.test',
        'database' => 'app',
        'username' => 'app',
        'password' => DATABASE_INSPECTION_SECRET,
    ])->assertCreated();

    $executor = new FakeDatabaseInspectionExecutor(
        queryResult: new DatabaseQueryResult(['email'], [['email' => 'owner@example.test']], 1, false),
    );
    app()->instance(DatabaseInspectionExecutor::class, $executor);

    $response = $this->postJson('/api/v1/database-connections/app/query', [
        'sql' => 'SELECT email FROM users WHERE token='.DATABASE_INSPECTION_SECRET,
        'write' => false,
    ]);

    $response
        ->assertOk()
        ->assertJsonPath('data.slug', 'app')
        ->assertJsonPath('data.driver', 'mysql')
        ->assertJsonPath('data.write', false)
        ->assertJsonPath('data.columns.0', 'email')
        ->assertJsonPath('data.rows.0.email', 'owner@example.test')
        ->assertJsonPath('data.row_count', 1)
        ->assertJsonPath('data.truncated', false)
        ->assertJsonMissingPath('data.password');

    expect($response->getContent())->not->toContain(DATABASE_INSPECTION_SECRET);
    expect($executor->queries)->toBe([
        [
            'slug' => 'app',
            'sql' => 'SELECT email FROM users WHERE token='.DATABASE_INSPECTION_SECRET,
            'write' => false,
        ],
    ]);

    $activity = Activity::query()->where('request_id', $response->json('meta.request_id'))->sole();
    $encoded = json_encode($activity->properties?->toArray() ?? [], JSON_THROW_ON_ERROR);

    expect($activity->command)
        ->toBe('database:query')
        ->and($encoded)
        ->not->toContain(DATABASE_INSPECTION_SECRET)
        ->and($activity->properties?->get('input'))
        ->toMatchArray(['write' => false]);
});

it('refuses write SQL without the write flag and executes it when gated', function (): void {
    $this->postJson('/api/v1/database-connections', [
        'slug' => 'app',
        'driver' => 'mysql',
        'host' => 'db.example.test',
        'database' => 'app',
        'username' => 'app',
        'password' => DATABASE_INSPECTION_SECRET,
    ])->assertCreated();

    $executor = new FakeDatabaseInspectionExecutor(
        queryResult: new DatabaseQueryResult([], [], 1, false),
    );
    app()->instance(DatabaseInspectionExecutor::class, $executor);

    $this->postJson('/api/v1/database-connections/app/query', [
        'sql' => 'DELETE FROM users',
    ])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'database.write_required');

    expect($executor->queries)->toBe([]);

    $this->postJson('/api/v1/database-connections/app/query', [
        'sql' => 'DELETE FROM users',
        'write' => true,
    ])
        ->assertOk()
        ->assertJsonPath('data.write', true)
        ->assertJsonPath('data.row_count', 1);

    expect($executor->queries[0] ?? null)->toMatchArray([
        'sql' => 'DELETE FROM users',
        'write' => true,
    ]);
});

it('refuses stacked SQL statements', function (): void {
    $this->postJson('/api/v1/database-connections', [
        'slug' => 'app',
        'driver' => 'mysql',
        'host' => 'db.example.test',
        'database' => 'app',
        'username' => 'app',
        'password' => DATABASE_INSPECTION_SECRET,
    ])->assertCreated();

    $executor = new FakeDatabaseInspectionExecutor;
    app()->instance(DatabaseInspectionExecutor::class, $executor);

    $this->postJson('/api/v1/database-connections/app/query', [
        'sql' => 'SELECT 1; DELETE FROM users',
    ])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'database.sql_multiple_statements');

    expect($executor->queries)->toBe([]);
});

it('lists schema and describes tables on a registered connection', function (): void {
    $this->postJson('/api/v1/database-connections', [
        'slug' => 'app',
        'driver' => 'pgsql',
        'host' => 'pg.example.test',
        'database' => 'app',
        'username' => 'app',
        'password' => DATABASE_INSPECTION_SECRET,
    ])->assertCreated();

    app()->instance(DatabaseInspectionExecutor::class, new FakeDatabaseInspectionExecutor);

    $this->getJson('/api/v1/database-connections/app/tables')
        ->assertOk()
        ->assertJsonPath('data.slug', 'app')
        ->assertJsonPath('data.tables.0', 'users');

    $this->getJson('/api/v1/database-connections/app/schema')
        ->assertOk()
        ->assertJsonPath('data.tables.0.name', 'users')
        ->assertJsonPath('data.tables.0.columns.0.name', 'id');

    $describe = $this->getJson('/api/v1/database-connections/app/describe/users');
    $describe
        ->assertOk()
        ->assertJsonPath('data.table', 'users')
        ->assertJsonPath('data.columns.0.primary', true);

    expect(Activity::query()->where('request_id', $describe->json('meta.request_id'))->sole()->command)
        ->toBe('database:describe');

    app()->instance(DatabaseInspectionExecutor::class, new FakeDatabaseInspectionExecutor(
        failure: new ResourceOperationException(
            errorCode: 'database.table_missing',
            message: 'Table [orders] was not found.',
            status: 404,
        ),
    ));

    $this->getJson('/api/v1/database-connections/app/describe/orders')
        ->assertNotFound()
        ->assertJsonPath('error.code', 'database.table_missing');
});

it('runs sqlite queries on the owning node and keeps SQL off argv', function (): void {
    $worker = Node::query()->create([
        'name' => 'worker',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.11',
        'public_ssh_port' => 22,
        'user' => 'orbit',
        'tld' => null,
        'wireguard_ip' => '10.44.0.8',
    ]);

    $this->postJson('/api/v1/database-connections', [
        'slug' => 'local',
        'driver' => 'sqlite',
        'node_id' => $worker->id,
        'path' => '/var/lib/app/database.sqlite',
    ])->assertCreated();

    $ssh = new class implements SshExecutor
    {
        public ?SshConnection $connection = null;

        public ?RemoteCommand $command = null;

        public function execute(SshConnection $connection, RemoteCommand $command): CommandResult
        {
            $this->connection = $connection;
            $this->command = $command;

            return new CommandResult(0, '[{"name":"users"}]', '', 12, false);
        }
    };
    app()->instance(SshExecutor::class, $ssh);

    $this->getJson('/api/v1/database-connections/local/tables')
        ->assertOk()
        ->assertJsonPath('data.tables.0', 'users');

    expect($ssh->connection?->host)
        ->toBe('10.44.0.8')
        ->and($ssh->command?->arguments)
        ->toBe(['sqlite3', '-json', '--readonly', '--', '/var/lib/app/database.sqlite'])
        ->and(implode("\0", $ssh->command?->arguments ?? []))
        ->not->toContain('SELECT')
        ->and($ssh->command?->protectedInput)
        ->not->toBeNull();
});

it('refuses sqlite inspection without an associated node and unknown slugs', function (): void {
    $this->postJson('/api/v1/database-connections', [
        'slug' => 'local',
        'driver' => 'sqlite',
        'path' => '/var/lib/app/database.sqlite',
    ])->assertCreated();

    $this->postJson('/api/v1/database-connections/local/query', [
        'sql' => 'SELECT 1',
    ])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'database.sqlite_node_required');

    $this->postJson('/api/v1/database-connections/missing/query', [
        'sql' => 'SELECT 1',
    ])
        ->assertNotFound()
        ->assertJsonPath('error.code', 'http.404');

    expect(DatabaseConnection::query()->where('slug', 'missing')->exists())->toBeFalse();
});
