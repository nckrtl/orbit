<?php

declare(strict_types=1);

use App\Domain\DatabaseServers\ValkeyAclUser;
use App\Domain\DatabaseServers\ValkeyServerAdmin;
use App\Domain\Doctor\NodeInspectionData;
use App\Domain\Doctor\NodeStateInspector;
use App\Domain\Shared\ResourceOperationException;
use App\Models\DatabaseConnection;
use App\Models\DatabaseServer;
use App\Models\DatabaseUser;
use App\Models\Node;
use App\Models\Process;
use Tests\Support\FakeValkeyServerAdmin;

beforeEach(function (): void {
    $node = Node::query()->create([
        'name' => 'doctor-valkey', 'status' => 'active', 'platform' => 'linux',
        'public_ssh_host' => '192.0.2.80', 'user' => 'orbit', 'wireguard_ip' => '10.44.0.80',
    ]);
    $this->markAsGateway($node);
    $this->withServerVariables(['REMOTE_ADDR' => $node->wireguard_ip]);
    $this->mock(NodeStateInspector::class)->shouldReceive('inspect')
        ->andReturn(new NodeInspectionData(true, 'linux', 'x86_64', true));
    $process = Process::query()->create([
        'owner_type' => Node::class, 'owner_id' => $node->id, 'name' => 'valkey',
        'runtime' => 'docker', 'runtime_config' => [], 'desired_state' => 'running', 'status' => 'active',
    ]);
    $this->server = DatabaseServer::query()->create([
        'slug' => 'doctor-valkey', 'driver' => 'redis', 'node_id' => $node->id,
        'process_id' => $process->id, 'tag' => '8.1', 'port' => 6379,
        'root_password' => 'doctor-admin-secret', 'status' => 'active',
    ]);
    $this->connection = DatabaseConnection::query()->create([
        'slug' => 'private-client', 'driver' => 'redis', 'node_id' => $node->id,
        'database_server_id' => $this->server->id, 'username' => 'private-client', 'password' => 'doctor-client-secret',
    ]);
    DatabaseUser::query()->create([
        'database_connection_id' => $this->connection->id, 'username' => 'private-client',
        'password' => 'doctor-client-secret', 'privileges' => 'on ~private:* resetchannels -@all +get',
    ]);
    $this->admin = new FakeValkeyServerAdmin;
    $this->admin->users = [
        'default' => new ValkeyAclUser('default', 'on sanitize-payload ~* &* +@all', [hash('sha256', 'doctor-admin-secret')]),
        'private-client' => new ValkeyAclUser('private-client', 'on ~private:* resetchannels -@all +get', [hash('sha256', 'doctor-client-secret')]),
    ];
    app()->instance(ValkeyServerAdmin::class, $this->admin);
    $this->request = ['node_id' => $node->id, 'families' => ['database_server']];
});

it('reports matching ACL users as healthy through the Doctor endpoint without writes', function (): void {
    DatabaseConnection::query()->create([
        'slug' => 'adopted-default', 'driver' => 'redis', 'database_server_id' => $this->server->id,
        'username' => null, 'password' => 'doctor-admin-secret',
    ]);

    $this->postJson('/api/v1/doctor', $this->request)->assertOk()
        ->assertJsonPath('data.nodes.0.families.0.family', 'database_server')
        ->assertJsonPath('data.nodes.0.families.0.checked', 1)
        ->assertJsonPath('data.nodes.0.families.0.issues', [])
        ->assertJsonPath('data.healthy', true);

    expect($this->admin->calls)->toBe(['anonymous ping', 'config get aclfile', 'acl list']);
});

it('reports an open adopted server with both open and ACL mismatch issues', function (): void {
    $this->admin->anonymous = true;
    $this->admin->users['default'] = new ValkeyAclUser('default', 'on nopass ~* &* +@all', []);

    $response = $this->postJson('/api/v1/doctor', $this->request)->assertOk();

    expect(array_column($response->json('data.nodes.0.families.0.issues'), 'code'))
        ->toBe(['database_server.open', 'database_server.acl_mismatch']);
});

it('reports a missing or different live ACL file without exposing its path', function (?string $file): void {
    $this->admin->aclFile = $file;

    $this->postJson('/api/v1/doctor', $this->request)->assertOk()
        ->assertJsonPath('data.nodes.0.families.0.issues.0.code', 'database_server.acl_file_missing')
        ->assertJsonPath('data.nodes.0.families.0.issues.0.observed', 'missing or different');
})->with([null, '/tmp/private-acl']);

it('reports ACL drift without exposing users rules passwords or hashes', function (string $change): void {
    match ($change) {
        'missing admin' => array_shift($this->admin->users),
        'missing client' => array_pop($this->admin->users),
        'extra user' => $this->admin->users['unrecorded'] = new ValkeyAclUser('unrecorded', 'on nopass ~* &* +@all', []),
        'admin rights' => $this->admin->users['default'] = new ValkeyAclUser('default', 'on ~* &* -@all', [hash('sha256', 'doctor-admin-secret')]),
        'admin password' => $this->admin->users['default'] = new ValkeyAclUser('default', 'on sanitize-payload ~* &* +@all', [hash('sha256', 'wrong')]),
        'client password' => $this->admin->users['private-client'] = new ValkeyAclUser('private-client', 'on ~private:* resetchannels -@all +get', [hash('sha256', 'wrong')]),
        'extra password' => $this->admin->users['private-client'] = new ValkeyAclUser('private-client', 'on ~private:* resetchannels -@all +get', [hash('sha256', 'doctor-client-secret'), hash('sha256', 'wrong')]),
        'client rules' => $this->admin->users['private-client'] = new ValkeyAclUser('private-client', 'on sanitize-payload ~* &* +@all', [hash('sha256', 'doctor-client-secret')]),
        'missing record' => DatabaseUser::query()->delete(),
        'missing password' => $this->connection->update(['password' => null]),
    };

    $response = $this->postJson('/api/v1/doctor', $this->request)->assertOk()
        ->assertJsonPath('data.nodes.0.families.0.issues.0.code', 'database_server.acl_mismatch');

    foreach (['private-client', 'unrecorded', '~private:*', 'doctor-client-secret', 'doctor-admin-secret', hash('sha256', 'doctor-client-secret'), hash('sha256', 'doctor-admin-secret')] as $secret) {
        expect($response->getContent())->not->toContain($secret);
    }
})->with(['missing admin', 'missing client', 'extra user', 'admin rights', 'admin password', 'client password', 'extra password', 'client rules', 'missing record', 'missing password']);

it('reports connection failures as unverifiable', function (): void {
    $this->admin->failCommands = true;

    $this->postJson('/api/v1/doctor', $this->request)->assertOk()
        ->assertJsonPath('data.nodes.0.families.0.status', 'unverifiable')
        ->assertJsonPath('data.nodes.0.families.0.issues.0.code', 'database_server.inspection_failed');
});

it('skips MySQL servers and processes that should not run', function (string $skip): void {
    match ($skip) {
        'mysql' => $this->server->update(['driver' => 'mysql']),
        'stopped' => $this->server->process->update(['desired_state' => 'stopped']),
        'missing process' => $this->server->update(['process_id' => null]),
    };

    $this->postJson('/api/v1/doctor', $this->request)->assertOk()
        ->assertJsonPath('data.nodes.0.families.0.checked', 0);

    expect($this->admin->calls)->toBe([]);
})->with(['mysql', 'stopped', 'missing process']);

it('keeps an open finding when admin login fails and hides raw failures', function (): void {
    $this->mock(ValkeyServerAdmin::class, function ($mock): void {
        $mock->shouldReceive('acceptsAnonymous')->once()->andReturn(true);
        $mock->shouldReceive('aclFile')->once()->andThrow(new ResourceOperationException(
            'database.server_command_failed', 'doctor-admin-secret raw server failure', 502,
        ));
    });

    $response = $this->postJson('/api/v1/doctor', $this->request)->assertOk();

    expect(array_column($response->json('data.nodes.0.families.0.issues'), 'code'))
        ->toBe(['database_server.open', 'database_server.inspection_failed']);
    expect($response->getContent())->not->toContain('doctor-admin-secret')->not->toContain('raw server failure');
});
