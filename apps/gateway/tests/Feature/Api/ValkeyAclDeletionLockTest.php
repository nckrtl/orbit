<?php

declare(strict_types=1);

use App\Actions\DatabaseConnections\DropOwnedDatabasesAction;
use App\Actions\DatabaseConnections\DropServerDatabaseAction;
use App\Domain\DatabaseServers\ValkeyAclUser;
use App\Domain\DatabaseServers\ValkeyServerAdmin;
use App\Domain\Shared\ResourceOperationException;
use App\Models\DatabaseConnection;
use App\Models\DatabaseServer;
use App\Models\Node;
use Illuminate\Support\Facades\Cache;
use Tests\Support\FakeValkeyServerAdmin;

beforeEach(function (): void {
    $node = Node::query()->create([
        'name' => 'acl-lock-node', 'status' => 'active', 'platform' => 'linux',
        'public_ssh_host' => '192.0.2.80', 'user' => 'orbit', 'wireguard_ip' => '10.44.0.80',
    ]);
    $this->markAsGateway($node);
    $this->withServerVariables(['REMOTE_ADDR' => $node->wireguard_ip]);
    $this->server = DatabaseServer::query()->create([
        'slug' => 'acl-lock-server', 'driver' => 'redis', 'node_id' => $node->id,
        'tag' => '8.1', 'port' => 6379, 'root_password' => 'admin-secret', 'status' => 'active',
    ]);
    $this->connection = DatabaseConnection::query()->create([
        'slug' => 'acl-lock-client', 'driver' => 'redis', 'node_id' => $node->id,
        'database_server_id' => $this->server->id, 'username' => 'acl-lock-client', 'password' => 'client-secret',
    ]);
    $this->lockName = 'database-server:'.$this->server->id.':acl';
    $this->admin = new FakeValkeyServerAdmin;
    $this->admin->users['acl-lock-client'] = new ValkeyAclUser('acl-lock-client', 'on ~* -@all +get', [hash('sha256', 'client-secret')]);
    app()->instance(ValkeyServerAdmin::class, $this->admin);
});

it('refuses deleting while another ACL change owns the lock without touching the user or record', function (): void {
    $lock = Cache::lock($this->lockName, 120);
    expect($lock->get())->toBeTrue();

    try {
        $this->deleteJson('/api/v1/database-connections/acl-lock-client')->assertConflict()
            ->assertJsonPath('error.code', 'database.server_in_use');
    } finally {
        $lock->release();
    }

    $this->assertModelExists($this->connection);
    expect($this->admin->calls)->toBe([]);
    expect($this->admin->users)->toHaveKey('acl-lock-client');
});

it('holds the ACL lock through the remote deletion and the connection deletion', function (string $caller): void {
    $lockName = $this->lockName;
    $this->mock(ValkeyServerAdmin::class, function ($mock) use ($lockName): void {
        $mock->shouldReceive('deleteUser')->once()->andReturnUsing(function () use ($lockName): void {
            expect(Cache::lock($lockName, 120)->get())->toBeFalse();
        });
    });
    $recordDeletionChecked = false;
    DatabaseConnection::deleting(function () use ($lockName, &$recordDeletionChecked): void {
        expect(Cache::lock($lockName, 120)->get())->toBeFalse();
        $recordDeletionChecked = true;
    });

    if ($caller === 'API') {
        $this->deleteJson('/api/v1/database-connections/acl-lock-client')->assertOk();
    } else {
        app(DropOwnedDatabasesAction::class)->drop($this->connection);
    }

    $this->assertModelMissing($this->connection);
    expect($recordDeletionChecked)->toBeTrue();
    $lock = Cache::lock($lockName, 120);
    expect($lock->get())->toBeTrue();
    $lock->release();
})->with(['API', 'Instance removal']);

it('keeps the connection record and releases the lock when ACL persistence fails', function (): void {
    $this->mock(ValkeyServerAdmin::class, function ($mock): void {
        $mock->shouldReceive('deleteUser')->once()->andThrow(new ResourceOperationException(
            'database.server_command_failed', 'The ACL file could not be saved.', 502,
        ));
    });

    $this->deleteJson('/api/v1/database-connections/acl-lock-client')->assertStatus(502)
        ->assertJsonPath('error.code', 'database.server_command_failed');

    $this->assertModelExists($this->connection);
    $lock = Cache::lock($this->lockName, 120);
    expect($lock->get())->toBeTrue();
    $lock->release();
});

it('does not delete a recreated user when retrying with an already deleted connection', function (): void {
    $this->connection->delete();
    DatabaseConnection::query()->create([
        'slug' => 'replacement', 'driver' => 'redis', 'database_server_id' => $this->server->id,
        'username' => 'acl-lock-client', 'password' => 'new-secret',
    ]);

    app(DropServerDatabaseAction::class)->execute($this->connection);

    expect($this->admin->calls)->toBe([]);
    expect($this->admin->users)->toHaveKey('acl-lock-client');
});
