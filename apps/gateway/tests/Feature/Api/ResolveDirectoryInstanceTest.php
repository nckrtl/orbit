<?php

declare(strict_types=1);

use App\Infrastructure\Ssh\SshExecutor;
use App\Models\App as OrbitApp;
use App\Models\AppInstance;
use App\Models\Node;

use function Pest\Laravel\mock;

/** @return array{Node, AppInstance} */
function directory_resolution_fixture(): array
{
    $caller = Node::query()->create(['name' => 'directory-caller', 'public_ssh_host' => '192.0.2.80', 'wireguard_ip' => '10.44.0.80', 'user' => 'orbit', 'status' => 'active']);
    $caller->accessibleNodes()->attach($caller);
    $app = OrbitApp::query()->create(['name' => 'Directory', 'slug' => 'directory', 'repository_url' => 'https://example.test/app.git']);
    $instance = $app->appInstances()->create(['node_id' => $caller->id, 'name' => 'fixture', 'environment' => 'development', 'status' => 'active', 'checkout_path' => '/home/orbit/project']);
    mock(SshExecutor::class)->shouldNotReceive('execute');

    return [$caller, $instance];
}

describe('caller directory resolution', function (): void {
    it('selects the root and its descendants without requiring a Route', function (string $directory): void {
        [$caller, $instance] = directory_resolution_fixture();
        $response = $this->withServerVariables(['REMOTE_ADDR' => $caller->wireguard_ip])->get('/api/v1/instances/resolve-directory?directory='.rawurlencode($directory));
        $response->assertOk()->assertJsonPath('data', ['instance_id' => $instance->id, 'app_id' => $instance->app_id, 'node_id' => $caller->id, 'environment' => 'development']);
        expect($response->getContent())->not->toContain('/home/orbit');
        $this->assertDatabaseCount('app_instance_dependency_scan_attempts', 0);
    })->with(['/home/orbit/project', '/home/orbit/project/public', '/home/orbit/project/child directory']);

    it('preserves trailing spaces as path bytes rather than selecting a trimmed sibling', function (): void {
        [$caller] = directory_resolution_fixture();
        $this->withServerVariables(['REMOTE_ADDR' => $caller->wireguard_ip])->get('/api/v1/instances/resolve-directory?directory=%2Fhome%2Forbit%2Fproject%20')
            ->assertNotFound()->assertJsonMissingPath('data');
    });

    it('rejects unmanaged paths and prefix siblings', function (string $directory): void {
        [$caller] = directory_resolution_fixture();
        $this->withServerVariables(['REMOTE_ADDR' => $caller->wireguard_ip])->get('/api/v1/instances/resolve-directory?directory='.rawurlencode($directory))
            ->assertNotFound()->assertJsonPath('error.code', 'dependencies.target_not_found')->assertJsonMissingPath('data');
    })->with(['/home/orbit', '/home/orbit/project-other', '/unmanaged', '/']);

    it('never selects a matching root on another Node even with fleet authority', function (): void {
        [$caller, $instance] = directory_resolution_fixture();
        $other = Node::query()->create(['name' => 'other', 'public_ssh_host' => '192.0.2.81', 'wireguard_ip' => '10.44.0.81', 'user' => 'orbit', 'status' => 'active']);
        $caller->roles()->create(['role' => 'gateway', 'status' => 'active']);
        $instance->update(['node_id' => $other->id]);
        $this->withServerVariables(['REMOTE_ADDR' => $caller->wireguard_ip])->get('/api/v1/instances/resolve-directory?directory=/home/orbit/project')
            ->assertNotFound()->assertJsonMissingPath('data');
    });

    it('rejects nested ownership without filtering unavailable instances', function (string $root, string $status): void {
        [$caller, $instance] = directory_resolution_fixture();
        $instance->app->appInstances()->create(['node_id' => $caller->id, 'name' => 'overlap', 'environment' => 'development', 'status' => $status, 'checkout_path' => $root]);
        $this->withServerVariables(['REMOTE_ADDR' => $caller->wireguard_ip])->get('/api/v1/instances/resolve-directory?directory=/home/orbit/project/child')
            ->assertStatus(409)->assertJsonPath('error.code', 'dependencies.target_ambiguous')->assertJsonMissingPath('data');
    })->with([['/home/orbit/project/child', 'active'], ['/home/orbit/project/child', 'reserved']]);

    it('refuses unavailable matches', function (array $changes): void {
        [$caller, $instance] = directory_resolution_fixture();
        $instance->update($changes);
        $this->withServerVariables(['REMOTE_ADDR' => $caller->wireguard_ip])->get('/api/v1/instances/resolve-directory?directory=/home/orbit/project')
            ->assertStatus(409)->assertJsonPath('error.code', 'dependencies.instance_unavailable');
    })->with([[['status' => 'reserved']], [['migration_required' => true]]]);

    it('uses the registered production home rather than a stale checkout field', function (): void {
        [$caller, $instance] = directory_resolution_fixture();
        $instance->update(['environment' => 'production', 'production_home' => '/home/orbit-app-1', 'production_user' => 'orbit-app-1']);
        $this->withServerVariables(['REMOTE_ADDR' => $caller->wireguard_ip])->get('/api/v1/instances/resolve-directory?directory=/home/orbit-app-1/releases/release/public')
            ->assertOk()->assertJsonPath('data.instance_id', $instance->id)->assertJsonPath('data.environment', 'production');
        $this->get('/api/v1/instances/resolve-directory?directory=/home/orbit/project')->assertNotFound();
    });

    it('rejects noncanonical caller paths', function (string $directory): void {
        [$caller] = directory_resolution_fixture();
        $this->withServerVariables(['REMOTE_ADDR' => $caller->wireguard_ip])->get('/api/v1/instances/resolve-directory?directory='.rawurlencode($directory))
            ->assertStatus(422)->assertJsonMissingPath('data');
    })->with(['relative', '/home/orbit/project/', '/home//orbit/project', '/home/orbit/./project', '/home/orbit/project/../project', "/home/orbit/project\0", '/home/orbit\\project', str_repeat('x', 4097)]);

    it('does not normalize a noncanonical registered root into ownership', function (): void {
        [$caller, $instance] = directory_resolution_fixture();
        $instance->update(['checkout_path' => '/home/orbit/./project']);
        $this->withServerVariables(['REMOTE_ADDR' => $caller->wireguard_ip])->get('/api/v1/instances/resolve-directory?directory=/home/orbit/project')->assertNotFound();
    });

    it('rejects Node overrides, domain mixing, arrays and bodies', function (string $query, string $body): void {
        [$caller] = directory_resolution_fixture();
        $this->withServerVariables(['REMOTE_ADDR' => $caller->wireguard_ip])->call('GET', '/api/v1/instances/resolve-directory'.$query, [], [], [], ['HTTP_ACCEPT' => 'application/json'], $body)->assertStatus(422);
    })->with([['?directory=/home/orbit/project&node_id=99', ''], ['?directory=/home/orbit/project&domain=a.test', ''], ['?directory[]=/home/orbit/project', ''], ['', ''], ['?directory=/home/orbit/project', '{}']]);

    it('requires an active peer and access to its own Node', function (): void {
        [$caller] = directory_resolution_fixture();
        $this->get('/api/v1/instances/resolve-directory?directory=/home/orbit/project')->assertForbidden();
        $caller->accessibleNodes()->detach();
        $other = Node::query()->create(['name' => 'grant-only', 'public_ssh_host' => '192.0.2.82', 'wireguard_ip' => '10.44.0.82', 'user' => 'orbit', 'status' => 'active']);
        $caller->accessibleNodes()->attach($other);
        $this->withServerVariables(['REMOTE_ADDR' => $caller->wireguard_ip])->get('/api/v1/instances/resolve-directory?directory=/home/orbit/project')
            ->assertNotFound()->assertJsonMissingPath('data');
    });
});
