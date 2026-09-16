<?php

declare(strict_types=1);

use App\Actions\AppInstances\Dependencies\PublishInstanceDependencyScanAction;
use App\Actions\AppInstances\Dependencies\ReadNpmDependencyGraphAction;
use App\Domain\AppInstances\Dependencies\DependencyEcosystem;
use App\Domain\AppInstances\Dependencies\DependencyGraph;
use App\Domain\AppInstances\Dependencies\DependencyScanResult;
use App\Domain\AppInstances\Dependencies\DependencySnapshot;
use App\Domain\AppInstances\Dependencies\DependencySource;
use App\Domain\AppInstances\Environment\AppInstanceEnvironmentOperationLock;
use App\Infrastructure\AppInstances\BunDependencyUpdatePresenceProgram;
use App\Infrastructure\AppInstances\ComposerDependencyUpdatePresenceProgram;
use App\Infrastructure\AppInstances\DependencyFilesProgram;
use App\Infrastructure\AppInstances\NativeAppInstanceEnvironmentOperationLock;
use App\Infrastructure\AppInstances\NpmDependencyUpdatePresenceProgram;
use App\Infrastructure\AppInstances\PnpmDependencyUpdatePresenceProgram;
use App\Infrastructure\AppInstances\YarnDependencyUpdatePresenceProgram;
use App\Infrastructure\Processes\CommandDeadline;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\SshExecutor;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Models\Activity;
use App\Models\App as OrbitApp;
use App\Models\AppInstance;
use App\Models\AppInstanceRemoval;
use App\Models\Node;
use App\Models\Route;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;

use function Pest\Laravel\mock;

/** @return array{Node, AppInstance} */
function dependency_api_fixture(): array
{
    $caller = Node::query()->create(['public_ssh_host' => '192.0.2.80', 'user' => 'orbit', 'name' => 'inventory-caller', 'wireguard_ip' => '10.44.0.80', 'status' => 'active']);
    $owner = Node::query()->create(['public_ssh_host' => '192.0.2.81', 'name' => 'inventory-owner', 'wireguard_ip' => '10.44.0.81', 'user' => 'orbit', 'status' => 'active']);
    $caller->accessibleNodes()->attach($owner);
    $app = OrbitApp::query()->create(['name' => 'Inventory', 'slug' => 'inventory', 'repository_url' => 'https://example.test/inventory.git']);
    $instance = $app->appInstances()->create(['node_id' => $owner->id, 'name' => 'development', 'environment' => 'development', 'status' => 'active', 'source_layout' => 'checkout', 'checkout_path' => '/home/orbit/project']);

    return [$caller, $instance];
}

/** @return array<string, mixed> */
function dependency_api_receipt(): array
{
    $files = array_fill_keys(DependencyFilesProgram::FILES, ['content' => null, 'hash' => null, 'error' => null]);
    foreach (['package.json' => 'manifest.json', 'package-lock.json' => 'package-lock-v3.json'] as $name => $fixture) {
        $content = file_get_contents(base_path('tests/Fixtures/Dependencies/Npm/'.$fixture));
        $files[$name] = ['content' => base64_encode($content), 'hash' => hash('sha256', $content), 'error' => null];
    }

    return ['root' => '/home/orbit/project', 'reference' => null, 'identity' => str_repeat('a', 64), 'files' => $files];
}

function dependency_api_remote(Closure $receipt): void
{
    mock(SshKeyProvider::class)->shouldReceive('privateKeyPath')->andReturn('/keys/private');
    mock(KnownHostsStore::class)->shouldReceive('path')->andReturn('/keys/known_hosts');
    mock(SshExecutor::class)->shouldReceive('execute')->andReturnUsing(fn (): CommandResult => new CommandResult(0, json_encode($receipt(), JSON_THROW_ON_ERROR), '', 1, false));
}

function dependency_api_seed(AppInstance $instance): void
{
    $graph = app(ReadNpmDependencyGraphAction::class)->execute(
        file_get_contents(base_path('tests/Fixtures/Dependencies/Npm/manifest.json')),
        file_get_contents(base_path('tests/Fixtures/Dependencies/Npm/package-lock-v3.json')),
    );
    app(PublishInstanceDependencyScanAction::class)->execute($instance->id, DependencyScanResult::refreshed(new DependencySnapshot(
        DependencyEcosystem::Npm, new DependencySource('/home/orbit/project', null, [], null), now()->toDateTimeImmutable(), $graph,
    )));
}

function dependency_api_removing(AppInstance $instance, bool $markedRemoving): void
{
    $instance->app->update(['repository_identity' => 'example.test/inventory']);
    $instance->update(['root' => 'public', 'branch' => 'main', 'starting_commit' => str_repeat('a', 40)]);
    $instance->node->roles()->create(['role' => 'app-dev', 'status' => 'active']);
    $route = Route::query()->create(['app_id' => $instance->app_id, 'node_id' => $instance->node_id, 'generation_basis_node_id' => $instance->node_id, 'domain' => 'inventory.test', 'provenance' => 'generated', 'publication' => 'private', 'status' => 'pending']);
    $route->targets()->create(['app_instance_id' => $instance->id, 'position' => 0]);
    $route->update(['status' => 'active']);
    $removal = AppInstanceRemoval::query()->create(['id' => (string) Str::uuid(), 'requested_app_instance_id' => $instance->id, 'requested_name' => $instance->name, 'force' => false, 'inventory_digest' => str_repeat('d', 64), 'total' => 1, 'status' => 'removing', 'current_step' => 'source_preparation']);
    $removal->members()->create([
        'position' => 0, 'app_instance_id' => $instance->id, 'app_id' => $instance->app_id, 'node_id' => $instance->node_id,
        'name' => $instance->name, 'environment' => $instance->environment, 'source_layout' => $instance->source_layout,
        'checkout_path' => $instance->checkout_path, 'linked_worktree_paths' => [], 'source_digest' => str_repeat('d', 64),
        'route_id' => $route->id, 'repository_identity' => 'example.test/inventory', 'root' => 'public', 'branch' => 'main',
        'starting_commit' => str_repeat('a', 40), 'source_commit' => str_repeat('a', 40),
        'common_repository_path' => $instance->checkout_path, 'source_identity' => '1:100',
    ]);
    if ($markedRemoving) {
        $instance->update(['status' => 'removing']);
    } else {
        $removal->update(['status' => 'failed', 'failed_step' => 'source_preparation', 'error_code' => 'instance.source_preparation_failed']);
    }
}

describe('single-instance dependency API', function (): void {
    it('reads never-scanned inventory without collection or attempts', function (): void {
        [$caller, $instance] = dependency_api_fixture();
        mock(SshExecutor::class)->shouldNotReceive('execute');

        $response = $this->withServerVariables(['REMOTE_ADDR' => $caller->wireguard_ip])->get("/api/v1/instances/{$instance->id}/dependencies", ['Accept' => 'application/json']);

        $response->assertOk()->assertExactJson([
            'data' => ['instance_id' => $instance->id, 'succeeded' => null,
                'composer' => ['ecosystem' => 'composer', 'state' => 'unknown', 'succeeded' => null, 'attempted_at' => null, 'error_code' => null, 'snapshot' => null],
                'javascript' => ['ecosystem' => 'npm', 'state' => 'unknown', 'succeeded' => null, 'attempted_at' => null, 'error_code' => null, 'snapshot' => null]],
            'meta' => ['request_id' => $response->headers->get('X-Orbit-Request-Id')],
        ]);
        $this->assertDatabaseCount('app_instance_dependency_scan_attempts', 0);
    });

    it('scans and reads complete graphs with safe provenance and independent scopes', function (): void {
        [$caller, $instance] = dependency_api_fixture();
        dependency_api_remote(fn (): array => dependency_api_receipt());
        $this->withServerVariables(['REMOTE_ADDR' => $caller->wireguard_ip]);

        $scan = $this->call('POST', "/api/v1/instances/{$instance->id}/dependencies/scan", server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], content: '{}');
        $read = $this->get("/api/v1/instances/{$instance->id}/dependencies", ['Accept' => 'application/json']);

        $scan->assertOk()->assertJsonPath('data.succeeded', true)->assertJsonPath('data.composer.state', 'absent')
            ->assertJsonPath('data.composer.snapshot.graph', null)->assertJsonPath('data.javascript.state', 'present')
            ->assertJsonCount(8, 'data.javascript.snapshot.graph.resolutions')->assertJsonCount(16, 'data.javascript.snapshot.graph.requirements');
        $read->assertOk()->assertJsonPath('data', $scan->json('data'));
        $resolutions = collect($read->json('data.javascript.snapshot.graph.resolutions'))->keyBy('id');
        expect($resolutions['node_modules/shared'])->toBe([
            'id' => 'node_modules/shared', 'ecosystem' => 'npm', 'name' => 'shared', 'version' => '1.2.0',
            'regular' => true, 'development' => true, 'source_reference' => null, 'integrity' => null,
        ]);
        expect($resolutions['node_modules/app-two/node_modules/shared']['version'])->toBe('2.2.0');
        expect($read->json('data.javascript.snapshot.graph.requirements'))->toContain([
            'from' => 'node_modules/app-one/node_modules/adapter', 'to' => null, 'name' => 'optional-peer',
            'constraint' => '*', 'kind' => 'peer', 'scope' => 'regular', 'optional' => true,
        ]);
        expect($read->json('data.javascript.snapshot.source.project_root'))->toBe('/home/orbit/project');
        expect($read->getContent())->not->toContain('fixture-secret', 'fixture-user', 'never-fetch', 'must-never-execute', 'hasInstallScript');
        expect(Activity::query()->get()->toJson())->not->toContain('fixture-secret', 'fixture-user', 'never-fetch', 'must-never-execute');
        $this->assertDatabaseCount('app_instance_dependency_scan_attempts', 2);
    });

    it('returns partial failure with the authoritative stale snapshot and original time', function (): void {
        [$caller, $instance] = dependency_api_fixture();
        $receipt = dependency_api_receipt();
        dependency_api_remote(function () use (&$receipt): array {
            return $receipt;
        });
        $this->withServerVariables(['REMOTE_ADDR' => $caller->wireguard_ip]);
        $this->travelTo(now()->startOfSecond());
        $first = $this->call('POST', "/api/v1/instances/{$instance->id}/dependencies/scan", server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], content: '{}');
        $this->travel(1)->minutes();
        $receipt['files']['package-lock.json'] = ['content' => null, 'hash' => null, 'error' => 'dependencies.unreadable_source'];

        $response = $this->call('POST', "/api/v1/instances/{$instance->id}/dependencies/scan", server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], content: '{}');

        $response->assertOk()->assertJsonPath('data.succeeded', false)->assertJsonPath('data.composer.succeeded', true)
            ->assertJsonPath('data.javascript.state', 'stale')->assertJsonPath('data.javascript.succeeded', false)
            ->assertJsonPath('data.javascript.error_code', 'dependencies.unreadable_source')
            ->assertJsonPath('data.javascript.snapshot', $first->json('data.javascript.snapshot'));
        expect($response->json('data.javascript.attempted_at'))->not->toBe($first->json('data.javascript.attempted_at'));
        $this->get("/api/v1/instances/{$instance->id}/dependencies", ['Accept' => 'application/json'])->assertJsonPath('data', $response->json('data'));
        $this->assertDatabaseCount('app_instance_dependency_scan_attempts', 4);
    });

    it('distinguishes a failed first attempt from a present empty project', function (): void {
        [$caller, $instance] = dependency_api_fixture();
        $publish = app(PublishInstanceDependencyScanAction::class);
        $time = new DateTimeImmutable('2026-09-15T15:00:00+00:00');
        $publish->execute($instance->id, DependencyScanResult::failed(DependencyEcosystem::Composer, $time, 'dependencies.unsupported_format'));
        $publish->execute($instance->id, DependencyScanResult::refreshed(new DependencySnapshot(DependencyEcosystem::Npm,
            new DependencySource('/home/orbit/project', null, [], null), $time, new DependencyGraph(DependencyEcosystem::Npm, [], []))));
        mock(SshExecutor::class)->shouldNotReceive('execute');

        $response = $this->withServerVariables(['REMOTE_ADDR' => $caller->wireguard_ip])->get("/api/v1/instances/{$instance->id}/dependencies", ['Accept' => 'application/json']);

        $response->assertOk()->assertJsonPath('data.succeeded', false)->assertJsonPath('data.composer.state', 'unknown')
            ->assertJsonPath('data.composer.succeeded', false)->assertJsonPath('data.composer.attempted_at', '2026-09-15T15:00:00+00:00')
            ->assertJsonPath('data.composer.error_code', 'dependencies.unsupported_format')->assertJsonPath('data.composer.snapshot', null)
            ->assertJsonPath('data.javascript.state', 'present')->assertJsonPath('data.javascript.snapshot.graph', ['resolutions' => [], 'requirements' => []]);
    });

    it('rejects unknown peers and unauthorized callers without reading or collecting inventory', function (string $method, string $suffix, bool $known): void {
        [$caller, $instance] = dependency_api_fixture();
        dependency_api_seed($instance);
        $caller->accessibleNodes()->detach();
        mock(SshExecutor::class)->shouldNotReceive('execute');

        $response = $this->withServerVariables(['REMOTE_ADDR' => $known ? $caller->wireguard_ip : '192.0.2.222'])
            ->call($method, "/api/v1/instances/{$instance->id}/dependencies{$suffix}", server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], content: $method === 'POST' ? '{}' : '');

        $response->assertForbidden()->assertJsonPath('error.code', $known ? 'node_access.required' : 'peer.identity_unknown')->assertJsonMissingPath('data');
        expect($response->getContent())->not->toContain('shared', 'snapshot', 'fixture-secret');
        $this->assertDatabaseCount('app_instance_dependency_scan_attempts', 1);
    })->with([['GET', '', true], ['POST', '/scan', true], ['GET', '', false], ['POST', '/scan', false]]);

    it('returns 404 for missing and nonnumeric IDs without collection', function (string $method, string $suffix, string $id): void {
        [$caller] = dependency_api_fixture();
        mock(SshExecutor::class)->shouldNotReceive('execute');

        $this->withServerVariables(['REMOTE_ADDR' => $caller->wireguard_ip])->call($method, "/api/v1/instances/{$id}/dependencies{$suffix}", server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], content: $method === 'POST' ? '{}' : '')
            ->assertNotFound()->assertJsonMissingPath('data');
        $this->assertDatabaseCount('app_instance_dependency_scan_attempts', 0);
    })->with([['GET', '', '999999'], ['POST', '/scan', '999999'], ['GET', '', 'other.test'], ['POST', '/scan', 'other.test']]);

    it('rejects unsupported input before scanning', function (string $method, string $suffix, string $body): void {
        [$caller, $instance] = dependency_api_fixture();
        mock(SshExecutor::class)->shouldNotReceive('execute');

        $this->withServerVariables(['REMOTE_ADDR' => $caller->wireguard_ip])->call($method, "/api/v1/instances/{$instance->id}/dependencies{$suffix}", server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], content: $body)
            ->assertUnprocessable()->assertJsonPath('error.code', 'validation.failed')->assertJsonMissingPath('data');
        $this->assertDatabaseCount('app_instance_dependency_scan_attempts', 0);
    })->with([['POST', '/scan', ''], ['POST', '/scan', '[]'], ['POST', '/scan', '{'], ['POST', '/scan', '{"command":"fixture-secret"}'], ['POST', '/scan?all=1', '{}'], ['GET', '?all=1', ''], ['GET', '', '{}']]);

    it('refuses removing and retained-removal targets without disclosing stored inventory', function (string $method, string $suffix, bool $markedRemoving): void {
        [$caller, $instance] = dependency_api_fixture();
        dependency_api_seed($instance);
        dependency_api_removing($instance, $markedRemoving);
        mock(SshExecutor::class)->shouldNotReceive('execute');

        $this->withServerVariables(['REMOTE_ADDR' => $caller->wireguard_ip])->call($method, "/api/v1/instances/{$instance->id}/dependencies{$suffix}", server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], content: $method === 'POST' ? '{}' : '')
            ->assertConflict()->assertJsonPath('error.code', 'dependencies.instance_unavailable')->assertJsonMissingPath('data');
        $this->assertDatabaseCount('app_instance_dependency_scan_attempts', 1);
    })->with([['GET', '', true], ['POST', '/scan', true], ['GET', '', false], ['POST', '/scan', false]]);

    it('rechecks authorization after collection before returning a graph', function (): void {
        [$caller, $instance] = dependency_api_fixture();
        dependency_api_remote(function () use ($caller): array {
            $caller->accessibleNodes()->detach();

            return dependency_api_receipt();
        });

        $this->withServerVariables(['REMOTE_ADDR' => $caller->wireguard_ip])->call('POST', "/api/v1/instances/{$instance->id}/dependencies/scan", server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], content: '{}')
            ->assertForbidden()->assertJsonPath('error.code', 'node_access.required')->assertJsonMissingPath('data');
    });
    it('allows implicit Gateway authority without a direct access edge', function (): void {
        [$caller, $instance] = dependency_api_fixture();
        $caller->accessibleNodes()->detach();
        $caller->roles()->create(['role' => 'gateway', 'status' => 'active']);
        mock(SshExecutor::class)->shouldNotReceive('execute');

        $this->withServerVariables(['REMOTE_ADDR' => $caller->wireguard_ip])->get("/api/v1/instances/{$instance->id}/dependencies", ['Accept' => 'application/json'])
            ->assertOk()->assertJsonPath('data.instance_id', $instance->id);
    });

    it('rejects inactive or migration-required targets before collection', function (array $attributes): void {
        [$caller, $instance] = dependency_api_fixture();
        $instance->update($attributes);
        mock(SshExecutor::class)->shouldNotReceive('execute');

        $this->withServerVariables(['REMOTE_ADDR' => $caller->wireguard_ip])->call('POST', "/api/v1/instances/{$instance->id}/dependencies/scan", server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], content: '{}')
            ->assertConflict()->assertJsonPath('error.code', 'dependencies.instance_unavailable')->assertJsonMissingPath('data');
        $this->assertDatabaseCount('app_instance_dependency_scan_attempts', 0);
    })->with([[['status' => 'reserved']], [['migration_required' => true]]]);

    it('does not return inventory when the instance disappears during collection', function (): void {
        [$caller, $instance] = dependency_api_fixture();
        dependency_api_seed($instance);
        dependency_api_remote(function () use ($instance): array {
            AppInstance::query()->whereKey($instance->id)->delete();

            return dependency_api_receipt();
        });

        $this->withServerVariables(['REMOTE_ADDR' => $caller->wireguard_ip])->call('POST', "/api/v1/instances/{$instance->id}/dependencies/scan", server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], content: '{}')
            ->assertNotFound()->assertJsonMissingPath('data');
        $this->assertDatabaseCount('app_instance_dependency_observations', 0);
        $this->assertDatabaseCount('app_instance_dependency_scan_attempts', 0);
    });

    it('does not return retained inventory when removal begins during collection', function (): void {
        [$caller, $instance] = dependency_api_fixture();
        dependency_api_seed($instance);
        $calls = 0;
        dependency_api_remote(function () use ($instance, &$calls): array {
            if (++$calls === 1) {
                dependency_api_removing($instance, true);
            }

            return dependency_api_receipt();
        });

        $this->withServerVariables(['REMOTE_ADDR' => $caller->wireguard_ip])->call('POST', "/api/v1/instances/{$instance->id}/dependencies/scan", server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], content: '{}')
            ->assertConflict()->assertJsonPath('error.code', 'dependencies.instance_unavailable')->assertJsonMissingPath('data');
        $this->assertDatabaseCount('app_instance_dependency_resolutions', 8);
    });

    it('maps lifecycle lock contention to a stable 409 without attempts', function (): void {
        [$caller, $instance] = dependency_api_fixture();
        $directory = sys_get_temp_dir().'/orbit-api-busy-'.Str::uuid();
        $owner = new NativeAppInstanceEnvironmentOperationLock($directory, new CommandDeadline);
        $time = 0.0;
        $clock = static function () use (&$time): float {
            return $time;
        };
        $contender = new NativeAppInstanceEnvironmentOperationLock($directory, new CommandDeadline($clock), $clock, static function (int $wait) use (&$time): void {
            $time += $wait / 1_000_000;
        });
        app()->instance(AppInstanceEnvironmentOperationLock::class, $contender);
        mock(SshExecutor::class)->shouldNotReceive('execute');
        try {
            $response = $owner->run([$instance->id], fn () => $this->withServerVariables(['REMOTE_ADDR' => $caller->wireguard_ip])->call('POST', "/api/v1/instances/{$instance->id}/dependencies/scan", server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], content: '{}'));
            $response->assertConflict()->assertJsonPath('error.code', 'dependencies.operation_busy')->assertJsonMissingPath('data');
            $this->assertDatabaseCount('app_instance_dependency_scan_attempts', 0);
        } finally {
            new Filesystem()->deleteDirectory($directory);
        }
    });

});

describe('single-instance dependency update API', function (): void {
    it('updates development packages and returns step outcomes with refreshed inventory', function (): void {
        [$caller, $instance] = dependency_api_fixture();
        $kinds = [];
        $receipt = dependency_api_receipt();
        $composerManifest = file_get_contents(base_path('tests/Fixtures/Dependencies/Composer/composer2-manifest.json'));
        $composerLock = file_get_contents(base_path('tests/Fixtures/Dependencies/Composer/composer2-lock.json'));
        $receipt['files']['composer.json'] = ['content' => base64_encode($composerManifest), 'hash' => hash('sha256', $composerManifest), 'error' => null];
        $receipt['files']['composer.lock'] = ['content' => base64_encode($composerLock), 'hash' => hash('sha256', $composerLock), 'error' => null];
        mock(SshKeyProvider::class)->shouldReceive('privateKeyPath')->andReturn('/keys/private');
        mock(KnownHostsStore::class)->shouldReceive('path')->andReturn('/keys/known_hosts');
        mock(SshExecutor::class)->shouldReceive('execute')->andReturnUsing(function ($connection, $command) use (&$kinds, $receipt): CommandResult {
            $input = $command->input;
            $kind = match (true) {
                $input === YarnDependencyUpdatePresenceProgram::render() => 'yarn',
                $input === ComposerDependencyUpdatePresenceProgram::render() => 'composer-inspect',
                $input === NpmDependencyUpdatePresenceProgram::render() => 'npm-inspect',
                $input === PnpmDependencyUpdatePresenceProgram::render() => 'pnpm',
                $input === BunDependencyUpdatePresenceProgram::render() => 'bun',
                $input === DependencyFilesProgram::render() => 'collect',
                in_array('composer-update', $command->arguments, true) => 'composer-apply',
                in_array('npm-update', $command->arguments, true) => 'npm-apply',
                default => 'unknown',
            };
            $kinds[] = $kind;

            return match ($kind) {
                'yarn', 'pnpm', 'bun' => new CommandResult(0, '{"status":"absent"}', '', 1, false),
                'composer-inspect' => new CommandResult(0, '{"status":"present"}', '', 1, false),
                'npm-inspect' => new CommandResult(0, '{"status":"present","vp":{"path":"/home/orbit/.local/share/vite-plus/bin/vp","version":"0.3.0"}}', '', 1, false),
                'composer-apply', 'npm-apply' => new CommandResult(0, '', '', 12, false),
                'collect' => new CommandResult(0, json_encode($receipt, JSON_THROW_ON_ERROR), '', 1, false),
                default => throw new RuntimeException($kind),
            };
        });

        $response = $this->withServerVariables(['REMOTE_ADDR' => $caller->wireguard_ip])
            ->call('POST', "/api/v1/instances/{$instance->id}/dependencies/update", server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], content: '{}');

        $response->assertOk()->assertJsonPath('data.succeeded', true)
            ->assertJsonPath('data.composer.status', 'succeeded')
            ->assertJsonPath('data.javascript.status', 'succeeded')
            ->assertJsonPath('data.inventory.succeeded', true)
            ->assertJsonPath('data.error_code', null);
        expect($kinds)->toContain('composer-apply');
        expect($kinds)->toContain('npm-apply');
        expect(array_search('composer-apply', $kinds, true))->toBeLessThan(array_search('npm-apply', $kinds, true));
    });

    it('returns a typed production refusal without package invocation', function (): void {
        [$caller, $instance] = dependency_api_fixture();
        $instance->update(['environment' => 'production', 'production_user' => 'app_sample', 'production_home' => '/home/app_sample']);
        mock(SshExecutor::class)->shouldNotReceive('execute');

        $response = $this->withServerVariables(['REMOTE_ADDR' => $caller->wireguard_ip])
            ->call('POST', "/api/v1/instances/{$instance->id}/dependencies/update", server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], content: '{}');

        $response->assertOk()->assertJsonPath('data.succeeded', false)
            ->assertJsonPath('data.error_code', 'dependencies.production_update_forbidden')
            ->assertJsonPath('data.inventory', null)
            ->assertJsonPath('data.composer.status', 'not_run')
            ->assertJsonPath('data.javascript.status', 'not_run')
            ->assertJsonPath('data.may_have_mutated', false);
        $this->assertDatabaseCount('app_instance_dependency_scan_attempts', 0);
    });

    it('rejects unauthorized update callers without mutation', function (): void {
        [$caller, $instance] = dependency_api_fixture();
        $caller->accessibleNodes()->detach();
        mock(SshExecutor::class)->shouldNotReceive('execute');

        $this->withServerVariables(['REMOTE_ADDR' => $caller->wireguard_ip])
            ->call('POST', "/api/v1/instances/{$instance->id}/dependencies/update", server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], content: '{}')
            ->assertForbidden()->assertJsonPath('error.code', 'node_access.required')->assertJsonMissingPath('data');
        $this->assertDatabaseCount('app_instance_dependency_scan_attempts', 0);
    });

    it('rejects unsupported update input before mutation', function (string $body): void {
        [$caller, $instance] = dependency_api_fixture();
        mock(SshExecutor::class)->shouldNotReceive('execute');

        $this->withServerVariables(['REMOTE_ADDR' => $caller->wireguard_ip])
            ->call('POST', "/api/v1/instances/{$instance->id}/dependencies/update", server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], content: $body)
            ->assertUnprocessable()->assertJsonPath('error.code', 'validation.failed')->assertJsonMissingPath('data');
    })->with(['', '[]', '{"command":"fixture-secret"}']);
});
