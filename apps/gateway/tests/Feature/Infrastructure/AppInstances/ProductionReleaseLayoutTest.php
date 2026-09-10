<?php

declare(strict_types=1);

use App\Domain\AppInstances\AppInstancePhpVersionCatalog;
use App\Domain\AppInstances\AppInstanceState;
use App\Domain\AppInstances\ComposerSourceClassifier;
use App\Domain\Routes\RouteProvenance;
use App\Domain\Routes\RoutePublication;
use App\Domain\Routes\RouteStatus;
use App\Infrastructure\AppDev\AppDevCaddyConfigRenderer;
use App\Infrastructure\AppDev\AppDevSite;
use App\Infrastructure\AppDev\AppDevSiteRepository;
use App\Infrastructure\AppInstances\RemoteProductionAppInstanceSourceLifecycle;
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

it('derives production serving paths through current and resolves PHP roots after a release switch', function (): void {
    $node = Node::query()->create([
        'name' => 'release-layout',
        'status' => 'active',
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.216',
        'wireguard_ip' => '10.44.0.216',
        'user' => 'orbit',
    ]);
    $app = OrbitApp::query()->create([
        'name' => 'Release layout',
        'slug' => 'release-layout',
        'repository_url' => 'https://example.test/release-layout.git',
        'default_branch' => 'main',
        'root' => 'public',
    ]);
    $instance = AppInstance::query()->create([
        'app_id' => $app->id,
        'node_id' => $node->id,
        'name' => 'production',
        'environment' => 'production',
        'checkout_path' => '/home/orbit-app-216/releases/initial',
        'production_user' => 'orbit-app-216',
        'production_home' => '/home/orbit-app-216',
        'production_php_socket' => '/run/php/orbit-app-216.sock',
        'selected_php_version' => '8.5',
        'branch' => 'main',
        'starting_commit' => str_repeat('a', 40),
        'status' => AppInstanceState::SourceResolved,
    ]);
    $route = Route::query()->create([
        'app_id' => $app->id,
        'node_id' => $node->id,
        'hostname' => 'release-layout.example.test',
        'provenance' => RouteProvenance::Explicit,
        'publication' => RoutePublication::Public,
        'status' => RouteStatus::Pending,
    ]);
    $route->targets()->create(['app_instance_id' => $instance->id, 'position' => 0]);
    $route->update(['status' => RouteStatus::Active]);

    $site = new AppDevSiteRepository()
        ->forNode($node)
        ->sole();
    $production = new AppDevCaddyConfigRenderer()->render(collect([$site]));
    $development = new AppDevCaddyConfigRenderer()->render(collect([
        new AppDevSite(
            nodeId: $node->id,
            nodeAddress: '10.44.0.216',
            scope: 'development',
            checkoutPath: '/home/orbit/site',
            documentRoot: 'public',
            phpVersion: '8.5',
            hostname: 'development.test',
        ),
    ]));

    expect($instance->load('app')->effectiveRoot())
        ->toBe('/home/orbit-app-216/current/public')
        ->and($site->checkoutPath)
        ->toBe('/home/orbit-app-216/current')
        ->and($site->documentRoot)
        ->toBe('public')
        ->and($production)
        ->toContain(
            'root * /home/orbit-app-216/current/public',
            'php_fastcgi unix//run/php/orbit-app-216.sock {',
            'resolve_root_symlink',
        )
        ->and($development)
        ->toContain('php_fastcgi unix//run/php/orbit-development.sock')
        ->not->toContain('resolve_root_symlink');
});

it('keeps an existing flat production checkout on its recorded serving path', function (): void {
    $node = Node::query()->create([
        'name' => 'flat-production',
        'status' => 'active',
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.218',
        'wireguard_ip' => '10.44.0.218',
        'user' => 'orbit',
    ]);
    $app = OrbitApp::query()->create([
        'name' => 'Flat production',
        'slug' => 'flat-production',
        'repository_url' => 'https://example.test/flat-production.git',
        'default_branch' => 'main',
        'root' => 'public',
    ]);
    $instance = AppInstance::query()->create([
        'app_id' => $app->id,
        'node_id' => $node->id,
        'name' => 'production',
        'environment' => 'production',
        'checkout_path' => '/home/orbit-app-218',
        'production_user' => 'orbit-app-218',
        'production_home' => '/home/orbit-app-218',
        'selected_php_version' => '8.5',
        'branch' => 'main',
        'starting_commit' => str_repeat('b', 40),
        'status' => AppInstanceState::SourceResolved,
    ]);
    $route = Route::query()->create([
        'app_id' => $app->id,
        'node_id' => $node->id,
        'hostname' => 'flat-production.example.test',
        'provenance' => RouteProvenance::Explicit,
        'publication' => RoutePublication::Public,
        'status' => RouteStatus::Pending,
    ]);
    $route->targets()->create(['app_instance_id' => $instance->id, 'position' => 0]);
    $route->update(['status' => RouteStatus::Active]);

    $site = new AppDevSiteRepository()
        ->forNode($node)
        ->sole();
    $configuration = new AppDevCaddyConfigRenderer()->render(collect([$site]));

    expect($instance->load('app')->effectiveRoot())
        ->toBe('/home/orbit-app-218/public')
        ->and($site->checkoutPath)
        ->toBe('/home/orbit-app-218')
        ->and($configuration)
        ->toContain('root * /home/orbit-app-218/public')
        ->not->toContain('root * /home/orbit-app-218/current/');
});

it('authenticates a selected release before publication and clears only its serving link', function (): void {
    [$layout, $ssh, $instance] = orb216_release_layout_lifecycle([
        new CommandResult(0, '', '', 1, false),
        new CommandResult(0, '', '', 1, false),
    ]);

    $layout->validateCurrent($instance);
    $layout->clearCurrent($instance);

    expect($ssh->commands)
        ->toHaveCount(2)
        ->and($ssh->commands[0]->arguments)
        ->toBe([
            'bash',
            '-seu',
            '--',
            'https://example.test/release-layout.git',
            'orbit-app-216',
            '/home/orbit-app-216',
            (string) $instance->id,
            'public',
            '0',
        ])
        ->and($ssh->commands[0]->input)
        ->toContain(
            'sudo test -f "$marker"',
            'expected=$(printf \'%s\\0%s\\0%s\\0%s\\0\'',
            'case "$selected" in "$releases"/*)',
            'realpath -e -- "$selected_environment"',
            'case "$resolved_root" in "$selected"|"$selected"/*)',
        )
        ->and($ssh->commands[1]->arguments[8])
        ->toBe('1')
        ->and($ssh->commands[1]->input)
        ->toContain('sudo -u "$user" -H rm -- "$current"')
        ->not->toContain('rm -rf', 'rm -- "$releases"', 'rm -- "$environment"');
});

it('leaves legacy flat production layouts outside release validation and cleanup', function (): void {
    [$layout, $ssh, $instance] = orb216_release_layout_lifecycle([]);
    $instance->update(['checkout_path' => '/home/orbit-app-216']);

    $layout->validateCurrent($instance);
    $layout->clearCurrent($instance);

    expect($ssh->commands)->toBe([]);
});

/**
 * @param list<CommandResult> $results
 * @return array{RemoteProductionAppInstanceSourceLifecycle, AppDevFakeSshExecutor, AppInstance}
 */
function orb216_release_layout_lifecycle(array $results): array
{
    $ssh = new AppDevFakeSshExecutor($results);
    $executor = new AppProdSshExecutor(
        $ssh,
        new class implements SshKeyProvider {
            public function privateKeyPath(): string
            {
                return '/tmp/orbit-test-key';
            }

            public function publicKey(): string
            {
                return 'ssh-ed25519 test';
            }
        },
        new class implements KnownHostsStore {
            public function path(): string
            {
                return '/tmp/orbit-test-known-hosts';
            }

            public function put(string $host, int $port, HostKey $key): void {}
        },
    );
    $node = Node::query()->create([
        'name' => 'release-layout-remote',
        'status' => 'active',
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.217',
        'wireguard_ip' => '10.44.0.217',
        'user' => 'orbit',
    ]);
    $app = OrbitApp::query()->create([
        'name' => 'Release layout remote',
        'slug' => 'release-layout-remote',
        'repository_url' => 'https://example.test/release-layout.git',
        'default_branch' => 'main',
        'root' => 'public',
    ]);
    $instance = AppInstance::query()->create([
        'app_id' => $app->id,
        'node_id' => $node->id,
        'name' => 'production',
        'environment' => 'production',
        'checkout_path' => '/home/orbit-app-216/releases/initial',
        'production_user' => 'orbit-app-216',
        'production_home' => '/home/orbit-app-216',
    ]);

    return [
        new RemoteProductionAppInstanceSourceLifecycle(
            $executor,
            new ComposerSourceClassifier(new AppInstancePhpVersionCatalog),
        ),
        $ssh,
        $instance,
    ];
}
