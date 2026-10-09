<?php

declare(strict_types=1);

use App\Domain\GitHub\RepositoryReadAccess;
use App\Domain\Instances\Deployment\DeploymentRelease;
use App\Domain\Instances\InstanceState;
use App\Domain\Instances\ProductionPhpRuntimeIdentity;
use App\Domain\Shared\LifecycleStatus;
use App\Infrastructure\AppDev\DevelopmentCaddyConfigRenderer;
use App\Infrastructure\AppDev\DevelopmentSiteRepository;
use App\Infrastructure\AppProd\ProductionSshExecutor;
use App\Infrastructure\Instances\ProductionApplicationPaths;
use App\Infrastructure\Instances\ProductionPhpRuntimeConfigRenderer;
use App\Infrastructure\Instances\RemoteProductionDeployment;
use App\Infrastructure\Instances\RemoteProductionPhpRuntimeManager;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Ssh\HostKey;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Project;
use App\Models\Route;
use Tests\Support\AppDevFakeSshExecutor;

/*
 * Production Instances that existed before Routes with a web root reached production have no such
 * Route. The fixture holds what the code before that change rendered for them: the workload Caddy
 * file, every PHP-FPM file, the release `.env` link, and each command, with its arguments, standard
 * input, and protected input, that deploys, activates, converges, monitors, removes, or refreshes the
 * runtime on the Node. The current code must render the same bytes.
 */
it('renders existing production Instances byte for byte as before production web roots', function (): void {
    $node = Node::query()->create([
        'name' => 'prod-equivalence',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'architecture' => 'x86_64',
        'tld' => 'prod-equivalence.test',
        'public_ssh_host' => '192.0.2.71',
        'wireguard_ip' => '10.44.0.71',
        'user' => 'orbit',
    ]);
    orbit_test_set_app_placement_role($node, true);
    $instances = [
        prod_equivalence_instance($node, 'shop', 'public', 'shop.example.com'),
        prod_equivalence_instance($node, 'site', 'apps/site/public', 'site.example.com'),
    ];

    $fixture = __DIR__.'/../../../Fixtures/ProductionWebRootEquivalence/existing-production-instances.json';
    $rendered = json_encode(prod_equivalence_render($node, $instances), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";

    expect($rendered)->toBe(file_get_contents($fixture));
});

function prod_equivalence_instance(Node $node, string $slug, string $root, string $domain): Instance
{
    $project = Project::query()->create([
        'name' => ucfirst($slug),
        'slug' => $slug,
        'type' => 'laravel-app',
        'repository_url' => "https://example.test/{$slug}.git",
        'default_branch' => 'main',
        'root' => 'public',
    ]);
    $user = "orbit-{$slug}";
    $instance = Instance::query()->create([
        'project_id' => $project->id,
        'node_id' => $node->id,
        'name' => 'production',
        'environment' => 'production',
        'source_layout' => 'release',
        'checkout_path' => "/home/{$user}/releases/initial",
        'production_user' => $user,
        'production_home' => "/home/{$user}",
        'root' => $root,
        'branch' => 'main',
        'starting_commit' => str_repeat('a', 40),
        'selected_php_version' => '8.4',
        'source_is_laravel' => true,
        'provisioning_step' => 'active',
        'status' => InstanceState::Active,
    ]);
    $instance->update(ProductionPhpRuntimeIdentity::forProvisioning($instance->refresh(), '8.4')->attributes());
    $route = Route::query()->create([
        'project_id' => $project->id,
        'node_id' => $node->id,
        'domain' => $domain,
        'provenance' => 'explicit',
        'publication' => 'private',
        'status' => 'pending',
    ]);
    $route->targets()->create(['instance_id' => $instance->id, 'position' => 0]);
    $route->update(['status' => 'active']);

    return $instance->refresh();
}

/**
 * @param  list<Instance>  $instances
 * @return array<string, mixed>
 */
function prod_equivalence_render(Node $node, array $instances): array
{
    $rendered = ['caddy' => new DevelopmentCaddyConfigRenderer()->render(new DevelopmentSiteRepository()->forNode($node))];
    $renderer = new ProductionPhpRuntimeConfigRenderer;

    foreach ($instances as $instance) {
        $identity = ProductionPhpRuntimeIdentity::from($instance);
        $files = ['marker' => $identity->marker()];

        foreach ([false, true] as $metrics) {
            foreach ([false, true] as $initialRelease) {
                $configuration = $renderer->render($identity, $metrics, $initialRelease);
                $key = ($metrics ? 'metrics' : 'plain').'-'.($initialRelease ? 'initial' : 'current');
                $files[$key] = [
                    'main' => $configuration->main,
                    'pool' => $configuration->pool,
                    'local_defaults' => $configuration->localDefaults,
                    'master_ini' => $configuration->masterIni,
                    'unit' => $configuration->unit,
                ];
            }
        }

        $ssh = new AppDevFakeSshExecutor(array_fill(0, 4, new CommandResult(0, "20261009-a1\t".str_repeat('b', 40)."\n", '', 1, false)));
        $executor = prod_equivalence_executor($ssh);
        $deployment = new RemoteProductionDeployment($executor, app(RepositoryReadAccess::class), static fn (): string => '20261009-a1');
        $release = $deployment->prepare($instance, 'main');
        $deployment->activate($instance, new DeploymentRelease($release->name, $release->path, $release->commit));
        $runtime = static function (Closure $operation, array $results = []): array {
            $fake = new AppDevFakeSshExecutor($results);
            $operation(new RemoteProductionPhpRuntimeManager(new ProductionPhpRuntimeConfigRenderer, prod_equivalence_executor($fake), '/run/lock/orbit'));

            return array_map(prod_equivalence_command(...), $fake->commands);
        };

        $rendered[$instance->production_user] = [
            'php_fpm' => $files,
            'release_environment_link' => ProductionApplicationPaths::render('__APPLICATION_SUFFIX__|__ENVIRONMENT_TARGET__|__ENVIRONMENT_PATH__', $instance->root),
            'deployment_commands' => array_map(prod_equivalence_command(...), $ssh->commands),
            'php_fpm_converge_commands' => $runtime(static fn (RemoteProductionPhpRuntimeManager $manager) => $manager->converge($instance)),
            'php_fpm_monitor_commands' => $runtime(static fn (RemoteProductionPhpRuntimeManager $manager) => $manager->convergeMonitoring($instance, true)),
            'php_fpm_remove_commands' => $runtime(static fn (RemoteProductionPhpRuntimeManager $manager) => $manager->remove($instance)),
            'php_fpm_refresh_commands' => $runtime(
                static fn (RemoteProductionPhpRuntimeManager $manager) => $manager->refreshCache($instance),
                [new CommandResult(0, "COMPLETE\n", '', 1, false)],
            ),
        ];
    }

    return $rendered;
}

/** @return array{arguments: list<string>, input: ?string, protected_input: ?string} */
function prod_equivalence_command(RemoteCommand $command): array
{
    $protected = $command->protectedInput?->stream();

    return [
        'arguments' => $command->arguments,
        'input' => $command->input,
        'protected_input' => $protected === null ? null : (string) stream_get_contents($protected),
    ];
}

function prod_equivalence_executor(AppDevFakeSshExecutor $ssh): ProductionSshExecutor
{
    return new ProductionSshExecutor(
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
}
