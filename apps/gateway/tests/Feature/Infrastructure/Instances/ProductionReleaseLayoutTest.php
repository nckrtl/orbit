<?php

declare(strict_types=1);

use App\Domain\GitHub\RepositoryReadAccess;
use App\Domain\Instances\ComposerSourceClassifier;
use App\Domain\Instances\InstancePhpVersionCatalog;
use App\Domain\Instances\InstanceState;
use App\Domain\Routes\RouteProvenance;
use App\Domain\Routes\RoutePublication;
use App\Domain\Routes\RouteStatus;
use App\Infrastructure\AppDev\DevelopmentCaddyConfigRenderer;
use App\Infrastructure\AppDev\DevelopmentSite;
use App\Infrastructure\AppDev\DevelopmentSiteRepository;
use App\Infrastructure\AppProd\ProductionSshExecutor;
use App\Infrastructure\Instances\RemoteProductionInstanceSourceLifecycle;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Ssh\HostKey;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Project;
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
    $node->roles()->create(['role' => 'app-prod', 'status' => 'active']);
    $project = Project::query()->create([
        'name' => 'Release layout',
        'slug' => 'release-layout',
        'repository_url' => 'https://example.test/release-layout.git',
        'default_branch' => 'main',
        'apps' => fixture_apps('public'),
    ]);
    $instance = Instance::query()->create([
        'project_id' => $project->id,
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
        'status' => InstanceState::SourceResolved,
    ]);
    $route = Route::query()->create([
        'project_id' => $project->id,
        'node_id' => $node->id,
        'domain' => 'release-layout.example.test',
        'provenance' => RouteProvenance::Explicit,
        'publication' => RoutePublication::Public,
        'status' => RouteStatus::Pending,
    ]);
    $route->targets()->create(['instance_id' => $instance->id, 'position' => 0]);
    $route->update(['status' => RouteStatus::Active]);

    $site = new DevelopmentSiteRepository()
        ->forNode($node)
        ->sole();
    $production = new DevelopmentCaddyConfigRenderer()->render(collect([$site]));
    $development = new DevelopmentCaddyConfigRenderer()->render(collect([
        new DevelopmentSite(
            nodeId: $node->id,
            nodeAddress: '10.44.0.216',
            scope: 'development',
            checkoutPath: '/home/orbit/site',
            documentRoot: 'public',
            phpVersion: '8.5',
            domain: 'development.test',
        ),
    ]));

    expect($instance->load('project')->effectiveRoot())
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

it('uses the current-release root instead of a flat production home', function (): void {
    $node = Node::query()->create([
        'name' => 'flat-production',
        'status' => 'active',
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.218',
        'wireguard_ip' => '10.44.0.218',
        'user' => 'orbit',
    ]);
    $node->roles()->create(['role' => 'app-prod', 'status' => 'active']);
    $project = Project::query()->create([
        'name' => 'Flat production',
        'slug' => 'flat-production',
        'repository_url' => 'https://example.test/flat-production.git',
        'default_branch' => 'main',
        'apps' => fixture_apps('public'),
    ]);
    $instance = Instance::query()->create([
        'project_id' => $project->id,
        'node_id' => $node->id,
        'name' => 'production',
        'environment' => 'production',
        'checkout_path' => '/home/orbit-app-218',
        'production_user' => 'orbit-app-218',
        'production_home' => '/home/orbit-app-218',
        'selected_php_version' => '8.5',
        'branch' => 'main',
        'starting_commit' => str_repeat('b', 40),
        'status' => InstanceState::SourceResolved,
    ]);
    $route = Route::query()->create([
        'project_id' => $project->id,
        'node_id' => $node->id,
        'domain' => 'flat-production.example.test',
        'provenance' => RouteProvenance::Explicit,
        'publication' => RoutePublication::Public,
        'status' => RouteStatus::Pending,
    ]);
    $route->targets()->create(['instance_id' => $instance->id, 'position' => 0]);
    $route->update(['status' => RouteStatus::Active]);

    $site = new DevelopmentSiteRepository()
        ->forNode($node)
        ->sole();
    $configuration = new DevelopmentCaddyConfigRenderer()->render(collect([$site]));

    expect($instance->load('project')->effectiveRoot())
        ->toBe('/home/orbit-app-218/current/public')
        ->and($site->checkoutPath)
        ->toBe('/home/orbit-app-218/current')
        ->and($configuration)
        ->not->toContain('root * /home/orbit-app-218/public');
});

it('authenticates a selected release before publication and clears only its serving link', function (string $webRoot, string $environmentPath, string $target): void {
    [$layout, $ssh, $instance] = orb216_release_layout_lifecycle([
        new CommandResult(0, '', '', 1, false),
        new CommandResult(0, '', '', 1, false),
    ]);

    $instance->update(['app_overrides' => fixture_app_overrides($webRoot)]);
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
            $webRoot,
            '0',
        ])
        ->and($ssh->commands[0]->input)
        ->toContain(
            'sudo test -f "$marker"',
            'expected=$(printf \'%s\\0%s\\0%s\\0%s\\0\'',
            'case "$selected" in "$releases"/*)',
            'realpath -e -- "$selected_environment"',
            'case "$resolved_root" in "$selected"|"$selected"/*)',
            $environmentPath,
            'readlink -- "$release_environment")" = '.$target,
        )
        ->and($ssh->commands[1]->arguments[8])
        ->toBe('1')
        ->and($ssh->commands[1]->input)
        ->toContain('sudo -u "$user" -H rm -- "$current"')
        ->not->toContain('rm -rf', 'rm -- "$releases"', 'rm -- "$environment"');
})->with([
    'root public' => ['public', 'selected_environment="$selected/.env"', '../../.env'],
    'nested Laravel' => ['server/web/public', 'selected_environment="$selected${application_suffix}/.env"', '../../../../.env'],
]);

it('leaves legacy flat production layouts outside release validation and cleanup', function (): void {
    [$layout, $ssh, $instance] = orb216_release_layout_lifecycle([]);
    $instance->update(['checkout_path' => '/home/orbit-app-216']);

    $layout->validateCurrent($instance);
    $layout->clearCurrent($instance);

    expect($ssh->commands)->toBe([]);
});

/**
 * @param  list<CommandResult>  $results
 * @return array{RemoteProductionInstanceSourceLifecycle, AppDevFakeSshExecutor, Instance}
 */
function orb216_release_layout_lifecycle(array $results): array
{
    $ssh = new AppDevFakeSshExecutor($results);
    $executor = new ProductionSshExecutor(
        $ssh,
        new class implements SshKeyProvider
        {
            public function privateKeyPath(): string
            {
                return '/tmp/orbit-test-key';
            }

            public function publicKey(): string
            {
                return 'ssh-ed25519 test';
            }
        },
        new class implements KnownHostsStore
        {
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
    $node->roles()->create(['role' => 'app-prod', 'status' => 'active']);
    $project = Project::query()->create([
        'name' => 'Release layout remote',
        'slug' => 'release-layout-remote',
        'repository_url' => 'https://example.test/release-layout.git',
        'default_branch' => 'main',
        'apps' => fixture_apps('public'),
    ]);
    $instance = Instance::query()->create([
        'project_id' => $project->id,
        'node_id' => $node->id,
        'name' => 'production',
        'environment' => 'production',
        'checkout_path' => '/home/orbit-app-216/releases/initial',
        'production_user' => 'orbit-app-216',
        'production_home' => '/home/orbit-app-216',
    ]);

    return [
        new RemoteProductionInstanceSourceLifecycle(
            $executor,
            new ComposerSourceClassifier(new InstancePhpVersionCatalog),
            app(RepositoryReadAccess::class),
        ),
        $ssh,
        $instance,
    ];
}
