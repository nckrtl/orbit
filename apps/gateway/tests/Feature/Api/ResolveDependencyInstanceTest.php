<?php

declare(strict_types=1);

use App\Infrastructure\Ssh\SshExecutor;
use App\Models\App as OrbitApp;
use App\Models\AppInstance;
use App\Models\Cluster;
use App\Models\Node;
use App\Models\Route;

use function Pest\Laravel\mock;

/** @return array{Node, Route, AppInstance, AppInstance} */
function resolution_fixture(): array
{
    $cluster = Cluster::query()->create(['name' => 'resolution', 'state' => 'active']);
    $caller = Node::query()->create(['name' => 'resolver-caller', 'public_ssh_host' => '192.0.2.80', 'wireguard_ip' => '10.44.0.80', 'user' => 'orbit', 'status' => 'active']);
    $app = OrbitApp::query()->create(['name' => 'Resolve', 'slug' => 'resolve', 'repository_url' => 'https://example.test/resolve.git']);
    $instances = [];
    foreach ([81, 82] as $octet) {
        $node = Node::query()->create(['name' => 'private-owner-'.$octet, 'public_ssh_host' => '192.0.2.'.$octet, 'wireguard_ip' => '10.44.0.'.$octet, 'user' => 'orbit', 'status' => 'active', 'cluster_id' => $cluster->id]);
        $node->roles()->create(['role' => 'app-prod', 'status' => 'active']);
        $instances[] = $app->appInstances()->create(['node_id' => $node->id, 'name' => 'private-instance-'.$octet, 'environment' => 'production', 'status' => 'active', 'checkout_path' => '/home/orbit/resolve-'.$octet]);
        $caller->accessibleNodes()->attach($node);
    }
    $route = Route::query()->create(['app_id' => $app->id, 'cluster_id' => $cluster->id, 'domain' => 'resolve.example.test', 'provenance' => 'explicit', 'publication' => 'private', 'status' => 'pending']);
    $route->targets()->create(['app_instance_id' => $instances[0]->id, 'position' => 0]);
    $route->update(['status' => 'active']);

    return [$caller, $route, ...$instances];
}

describe('dependency domain resolution', function (): void {
    it('returns only the unique authorized instance identity with normalized domain', function (): void {
        [$caller, $route, $instance] = resolution_fixture();
        mock(SshExecutor::class)->shouldNotReceive('execute');
        $this->withServerVariables(['REMOTE_ADDR' => $caller->wireguard_ip])->get('/api/v1/instances/resolve?domain=%20RESOLVE.EXAMPLE.TEST%20')
            ->assertOk()->assertExactJson(['data' => [
                'domain' => $route->domain, 'instance_id' => $instance->id, 'app_id' => $instance->app_id,
                'node_id' => $instance->node_id, 'environment' => 'production',
            ], 'meta' => ['request_id' => $this->app['request']->attributes->get('orbit.request_id')]]);
        $this->assertDatabaseCount('app_instance_dependency_scan_attempts', 0);
    });

    it('refuses the entire pool even when every member is accessible', function (): void {
        [$caller, $route, , $second] = resolution_fixture();
        $route->targets()->create(['app_instance_id' => $second->id, 'position' => 1]);
        $this->withServerVariables(['REMOTE_ADDR' => $caller->wireguard_ip])->get('/api/v1/instances/resolve?domain='.$route->domain)
            ->assertStatus(409)->assertJsonPath('error.code', 'dependencies.target_ambiguous')->assertJsonMissingPath('data');
    });

    it('does not reveal inaccessible pool members or turn a filtered pool into one target', function (bool $pool): void {
        [$caller, $route, $first, $second] = resolution_fixture();
        if ($pool) {
            $route->targets()->create(['app_instance_id' => $second->id, 'position' => 1]);
        }
        $caller->accessibleNodes()->detach($pool ? $second->node_id : $first->node_id);
        $response = $this->withServerVariables(['REMOTE_ADDR' => $caller->wireguard_ip])->get('/api/v1/instances/resolve?domain='.$route->domain);
        $response->assertNotFound()->assertJsonPath('error.code', 'dependencies.target_not_found')->assertJsonMissingPath('data');
        expect($response->getContent())->not->toContain('private-owner', 'private-instance', 'app_id', 'node_id', 'instance_id', 'target_ambiguous');
        $missing = $this->get('/api/v1/instances/resolve?domain=missing.example.test');
        expect($response->json('error.code'))->toBe($missing->json('error.code'))
            ->and($response->json('error.message'))->toBe($missing->json('error.message'))
            ->and($response->json('error.details'))->toBe($missing->json('error.details'));
    })->with([false, true]);

    it('refuses empty and nonauthoritative Routes', function (string $state): void {
        [$caller, $route] = resolution_fixture();
        $route->update(['status' => 'pending']);
        if ($state === 'empty') {
            $route->targets()->delete();
            $route->update(['status' => 'active', 'target_set_step' => 'complete']);
        } elseif ($state === 'failed') {
            $route->update(['status' => 'failed', 'failed_step' => 'test', 'error_code' => 'route.failed']);
        }
        $this->withServerVariables(['REMOTE_ADDR' => $caller->wireguard_ip])->get('/api/v1/instances/resolve?domain='.$route->domain)
            ->assertNotFound()->assertJsonPath('error.code', 'dependencies.target_not_found');
    })->with(['empty', 'pending', 'failed']);

    it('accepts an authoritative activating Route', function (): void {
        [$caller, $route, $instance] = resolution_fixture();
        $route->update(['status' => 'activating']);
        $this->withServerVariables(['REMOTE_ADDR' => $caller->wireguard_ip])->get('/api/v1/instances/resolve?domain='.$route->domain)
            ->assertOk()->assertJsonPath('data.instance_id', $instance->id);
    });

    it('refuses an unavailable unique instance', function (array $change): void {
        [$caller, $route, $instance] = resolution_fixture();
        $instance->update($change);
        $this->withServerVariables(['REMOTE_ADDR' => $caller->wireguard_ip])->get('/api/v1/instances/resolve?domain='.$route->domain)
            ->assertStatus(409)->assertJsonPath('error.code', 'dependencies.instance_unavailable')->assertJsonMissingPath('data');
    })->with([[['status' => 'reserved']], [['migration_required' => true]]]);

    it('rejects invalid selectors without echoing input', function (string $domain): void {
        [$caller] = resolution_fixture();
        $response = $this->withServerVariables(['REMOTE_ADDR' => $caller->wireguard_ip])->get('/api/v1/instances/resolve?domain='.rawurlencode($domain));
        $response->assertStatus(422)->assertJsonMissingPath('data');
        expect($response->getContent())->not->toContain('sentinel-secret');
    })->with(['resolve', 'https://user:sentinel-secret@resolve.example.test', 'resolve.example.test/path', '*.example.test', 'resolve.example.test:443', "resolve.example.test\ninvalid", 'resolve.example.test.']);

    it('rejects missing, array, extra query input and a request body', function (string $query, string $body): void {
        [$caller] = resolution_fixture();
        $this->withServerVariables(['REMOTE_ADDR' => $caller->wireguard_ip])->call('GET', '/api/v1/instances/resolve'.$query, [], [], [], ['HTTP_ACCEPT' => 'application/json'], $body)
            ->assertStatus(422);
    })->with([['', ''], ['?domain[]=resolve.example.test', ''], ['?domain=resolve.example.test&all=1', ''], ['?domain=resolve.example.test', '{}']]);

    it('requires an active peer and some Node access', function (): void {
        [$caller, $route] = resolution_fixture();
        $this->get('/api/v1/instances/resolve?domain='.$route->domain)->assertForbidden();
        $caller->accessibleNodes()->detach();
        $this->withServerVariables(['REMOTE_ADDR' => $caller->wireguard_ip])->get('/api/v1/instances/resolve?domain='.$route->domain)
            ->assertForbidden()->assertJsonPath('error.code', 'node_access.required')->assertJsonMissingPath('data');
    });
});
