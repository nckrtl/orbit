<?php

declare(strict_types=1);

use App\Domain\AppDev\RuntimeConvergenceException;
use App\Domain\AppInstances\DeploymentLayout\DeploymentLayoutInventory;
use App\Domain\AppInstances\ProductionPhpRuntimeIdentity;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AppInstances\RemoteProductionLayoutConverter;
use App\Infrastructure\AppProd\AppProdSshExecutor;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Ssh\HostKey;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Models\App as OrbitApp;
use App\Models\AppInstance;
use App\Models\Node;
use App\Models\Route;
use Tests\Support\AppDevFakeSshExecutor;

it('inventories without mutation and emits idempotent authenticated conversion effects', function (): void {
    [$instance, $route] = orb217_remote_conversion_fixture();
    $tuning = "[orbit-orbit-app-217]\npm = ondemand\npm.max_children = 7\n";
    $payload = [
        'source_path' => '/home/orbit-app-217',
        'release_path' => '/home/orbit-app-217/releases/initial',
        'source_identity' => '8:217',
        'git_head' => str_repeat('a', 40),
        'git_state_hash' => hash('sha256', 'dirty'),
        'environment_hash' => hash('sha256', "KEY=value\n"),
        'sqlite_source_path' => '/home/orbit-app-217/storage/app.sqlite',
        'sqlite_hash' => hash('sha256', 'sqlite'),
        'sqlite_identity' => '8:218',
        'local_tuning_hash' => hash('sha256', $tuning),
        'local_tuning' => base64_encode($tuning),
        'route_id' => $route->id,
        'route_node_id' => $route->node_id,
        'route_hostname' => $route->hostname,
        'route_status' => $route->status->value,
        'route_targets_hash' => hash('sha256', json_encode([
            ['app_instance_id' => $instance->id, 'position' => 0],
        ], JSON_THROW_ON_ERROR)),
        'document_root' => 'public',
        'previous_socket' => "/run/php/orbit-app-instance-{$instance->id}.sock",
    ];
    $ssh = new AppDevFakeSshExecutor([
        new CommandResult(0, json_encode($payload, JSON_THROW_ON_ERROR)."\n", '', 1, false),
        new CommandResult(0, '', '', 1, false),
        new CommandResult(0, '', '', 1, false),
        new CommandResult(0, '', '', 1, false),
        new CommandResult(0, $tuning, '', 1, false),
        new CommandResult(0, '', '', 1, false),
        new CommandResult(0, '', '', 1, false),
    ]);
    $converter = new RemoteProductionLayoutConverter(orb217_app_prod_executor($ssh));

    $inventory = $converter->preflight($instance, $route, "KEY=value\n", 'storage/app.sqlite');
    $converter->moveSource($instance, $inventory);
    $converter->assertSqliteQuiescent($instance, $inventory);
    $converter->placePersistentState($instance, $inventory);
    $actualTuning = $converter->runtimeTuning($instance, $inventory);
    $instance->update(['checkout_path' => $inventory->releasePath]);
    $instance->update(ProductionPhpRuntimeIdentity::forProvisioning($instance->refresh(), '8.5')->attributes());
    $instance->refresh();
    $converter->validateServingAssociation($instance, $inventory);
    $converter->validatePlacedLayout($instance, $inventory);

    $preflight = $ssh->commands[0];
    $move = $ssh->commands[1]->input ?? '';
    $quiescence = implode(' ', $ssh->commands[2]->arguments);
    $persistent = implode(' ', $ssh->commands[3]->arguments);
    $runtime = implode(' ', $ssh->commands[4]->arguments);
    $serving = $ssh->commands[5]->input ?? '';
    $validation = $ssh->commands[6]->input ?? '';

    expect($inventory)
        ->toBeInstanceOf(DeploymentLayoutInventory::class)
        ->and($inventory->toArray())
        ->not->toHaveKeys(['local_tuning', 'environment'])
        ->and($actualTuning)
        ->toBe($tuning)
        ->and($preflight->arguments)
        ->toContain('python3', '/home/orbit-app-217', 'storage/app.sqlite')
        ->and($preflight->arguments[4])
        ->toContain(
            'status", "--porcelain=v2", "-z"',
            'SQLite source has an open file handle',
            'os.path.realpath(candidate) != candidate',
            'shared PHP tuning contains an unsupported directive',
            'source_marker',
            'dedicated_runtime',
        )
        ->not->toContain('os.rename(', 'os.replace(', 'os.unlink(')
        ->and($move)
        ->toContain(
            'mv -- "$home" "$staging"',
            'mv -- "$staging" "$release"',
            'release-layout',
            'expected_identity',
        )
        ->not->toContain('git fetch', 'git reset', 'git clean', 'git checkout')
        ->and($quiescence)
        ->toContain('os.O_NOFOLLOW', 'expected_identity', 'os.listdir("/proc")')
        ->and($persistent)
        ->toContain(
            'os.rename(".env", ".env"',
            'os.rename(source_leaf, "database.sqlite"',
            'os.O_NOFOLLOW',
            'environment_source is None and environment_destination is not None',
            'not source_exists and database_exists',
        )
        ->and($runtime)
        ->toContain("orbit-app-instance-{$instance->id}", 'orbit-orbit-app-217')
        ->and($serving)
        ->toContain('php_fastcgi unix/$socket {', 'root * $home/$document_root')
        ->and($validation)
        ->toContain(
            'test -S "$socket"',
            'realpath -e -- "$home/current"',
            'php_fastcgi unix/$socket {',
            'systemctl is-active',
            'database.sqlite',
            'expected_marker',
        );
});

it('maps expected preflight refusal to a bounded conflict and preserves transport failures', function (): void {
    [$instance, $route] = orb217_remote_conversion_fixture();
    $refused = new RemoteProductionLayoutConverter(orb217_app_prod_executor(new AppDevFakeSshExecutor([
        new CommandResult(42, '', 'sensitive remote detail', 1, false),
    ])));

    expect(fn () => $refused->preflight($instance, $route, "KEY=value\n", null))
        ->toThrow(function (ResourceOperationException $exception): void {
            expect($exception->status)
                ->toBe(409)
                ->and($exception->getMessage())
                ->toBe('The deployment-layout preflight refused conversion.')
                ->not->toContain('sensitive remote detail');
        });

    $transport = new RemoteProductionLayoutConverter(orb217_app_prod_executor(new AppDevFakeSshExecutor([
        new CommandResult(255, '', 'transport detail', 1, false),
    ])));

    expect(fn () => $transport->preflight($instance, $route, "KEY=value\n", null))
        ->toThrow(RuntimeConvergenceException::class);
});

/** @return array{AppInstance, Route} */
function orb217_remote_conversion_fixture(): array
{
    $node = Node::query()->create([
        'name' => 'layout-remote',
        'status' => 'active',
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.217',
        'wireguard_ip' => '10.44.0.217',
        'user' => 'orbit',
    ]);
    $app = OrbitApp::query()->create([
        'name' => 'Layout remote',
        'slug' => 'layout-remote',
        'repository_url' => 'https://example.test/layout-remote.git',
        'default_branch' => 'main',
        'root' => 'public',
    ]);
    $instance = AppInstance::query()->create([
        'id' => 217,
        'app_id' => $app->id,
        'node_id' => $node->id,
        'name' => 'default',
        'environment' => 'production',
        'checkout_path' => '/home/orbit-app-217',
        'production_user' => 'orbit-app-217',
        'production_home' => '/home/orbit-app-217',
        'selected_php_version' => '8.5',
        'source_is_laravel' => false,
        'provisioning_step' => 'active',
        'status' => 'active',
    ]);
    $route = Route::query()->create([
        'app_id' => $app->id,
        'node_id' => $node->id,
        'hostname' => 'layout-remote.test',
        'provenance' => 'explicit',
        'publication' => 'private',
    ]);
    $route->targets()->create(['app_instance_id' => $instance->id, 'position' => 0]);

    return [$instance->fresh(['app', 'node']), $route];
}

function orb217_app_prod_executor(AppDevFakeSshExecutor $ssh): AppProdSshExecutor
{
    return new AppProdSshExecutor(
        $ssh,
        new class implements SshKeyProvider
        {
            public function privateKeyPath(): string
            {
                return '/tmp/orbit-layout-key';
            }

            public function publicKey(): string
            {
                return 'ssh-ed25519 synthetic';
            }
        },
        new class implements KnownHostsStore
        {
            public function path(): string
            {
                return '/tmp/orbit-layout-known-hosts';
            }

            public function put(string $host, int $port, HostKey $key): void {}
        },
    );
}
