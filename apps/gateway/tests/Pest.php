<?php

declare(strict_types=1);

use App\Domain\AppDev\AgentationSiteProjection;
use App\Domain\AppDev\ClusterRouterDnsSelectionReconciler;
use App\Domain\AppDev\VitePortRuntime;
use App\Domain\Clusters\ClusterState;
use App\Domain\Firewall\RouterLanIngressReconciler;
use App\Domain\Instances\DependencyCopy\InstanceDependencyCopier;
use App\Domain\Instances\Deployment\DeploymentPhase;
use App\Domain\Instances\Deployment\DeploymentStep;
use App\Domain\Instances\Deployment\InstanceDeployStepStore;
use App\Domain\Logs\LogStreamStore;
use App\Domain\Nodes\NodeAgentRuntime;
use App\Domain\Nodes\RoleName;
use App\Domain\Processes\ProcessEnvironmentProjection;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Tasks\NullTaskReviewDiff;
use App\Domain\Tasks\TaskCheckRunner;
use App\Domain\Tasks\TaskReviewDiff;
use App\Domain\Tasks\TaskTurnReceipts;
use App\Domain\Tasks\TaskWorkspaceTopology;
use App\Infrastructure\Activity\ActivityShutdownFinalizer;
use App\Infrastructure\AgentView\CacheAgentStateView;
use App\Infrastructure\Caddy\Build\NodeCaddyBuilds;
use App\Infrastructure\Instances\DependencyUpdateSupervisorHost;
use App\Infrastructure\Logs\CacheLogStreamStore;
use App\Infrastructure\Nodes\NodeLocks;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Processes\NativeProcessRunner;
use App\Infrastructure\Processes\ProcessInvocation;
use App\Models\Cluster;
use App\Models\Instance;
use App\Models\Node;
use App\Models\NodeRole;
use App\Models\Project;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Sleep;
use Laravel\Ai\Classification;
use Tests\Support\FakeAgentationSiteProjection;
use Tests\Support\FakeClusterRouterDnsSelectionReconciler;
use Tests\Support\FakeInstanceDependencyCopier;
use Tests\Support\FakeNodeAgentRuntime;
use Tests\Support\FakeNodeCaddyBuilds;
use Tests\Support\FakeProcessEnvironmentProjection;
use Tests\Support\FakeRouterLanIngressReconciler;
use Tests\Support\FakeTaskCheckRunner;
use Tests\Support\FakeTaskTurnReceipts;
use Tests\Support\FakeTaskWorkspaceTopology;
use Tests\Support\FakeVitePortRuntime;
use Tests\Support\TestToolchain;
use Tests\TestCase;

require_once __DIR__.'/Support/InstanceEnvironmentMigration.php';
require_once __DIR__.'/Support/LegacySchemaMigration.php';
require_once __DIR__.'/Support/FakeNodeAgentRuntime.php';
require_once __DIR__.'/Support/Orb245TransferFakes.php';
require_once __DIR__.'/Support/AgentDriverTestSupport.php';
require_once __DIR__.'/Support/TaskNodeReachability.php';
require_once __DIR__.'/Support/ResponseFixtures.php';
require_once __DIR__.'/Helpers/MetricsRoleFixtures.php';
require_once __DIR__.'/Helpers/WebSocketRoleFixtures.php';
require_once __DIR__.'/Helpers/AgentViewFixtures.php';
require_once __DIR__.'/Helpers/AnalyticsRoleFixtures.php';
require_once __DIR__.'/Helpers/AnalyticsConnectionFixtures.php';
require_once __DIR__.'/Helpers/InstanceAnalyticsFixtures.php';
require_once __DIR__.'/Helpers/ConfigFixtures.php';

uses(TestCase::class, RefreshDatabase::class)
    ->beforeEach(function (): void {
        app()->instance(VitePortRuntime::class, new FakeVitePortRuntime);
        app()->instance(NodeAgentRuntime::class, new FakeNodeAgentRuntime);
        app()->instance(AgentationSiteProjection::class, new FakeAgentationSiteProjection);
        app()->instance(ProcessEnvironmentProjection::class, new FakeProcessEnvironmentProjection);
        app()->instance(RouterLanIngressReconciler::class, new FakeRouterLanIngressReconciler);
        app()->instance(ClusterRouterDnsSelectionReconciler::class, new FakeClusterRouterDnsSelectionReconciler);
        app()->instance(TaskTurnReceipts::class, new FakeTaskTurnReceipts);
        app()->instance(TaskCheckRunner::class, new FakeTaskCheckRunner);
        app()->instance(TaskReviewDiff::class, new NullTaskReviewDiff);
        // A dependency copy runs `cp` over SSH on the Node; tests record copy requests instead.
        app()->instance(InstanceDependencyCopier::class, new FakeInstanceDependencyCopier);
        app()->instance(TaskWorkspaceTopology::class, new FakeTaskWorkspaceTopology);
        // A Node Caddy build runs local `sudo` on a Gateway Node; tests record build requests instead.
        app()->instance(NodeCaddyBuilds::class, new FakeNodeCaddyBuilds);
        // The view's file store under ORBIT_HOME would outlive a test; each test gets its own.
        app()->instance(CacheAgentStateView::class, new CacheAgentStateView(Cache::store('array')));
        app()->instance(LogStreamStore::class, new CacheLogStreamStore(Cache::store('array')));
        // The same holds for the Node locks' file store.
        app()->instance(NodeLocks::class, new NodeLocks(Cache::store('array')));
        Classification::fake()->preventStrayClassifications();
        // Transitions wait for private DNS answers to expire; tests assert those waits instead.
        Sleep::fake(syncWithCarbon: true);
        // A streamed response a test never consumed stays armed; each test starts with none.
        ActivityShutdownFinalizer::forgetArmed();
    })
    ->in('Feature');

pest()
    ->tia()
    ->locally()
    ->filtered();

$tiaDirectory = getenv('ORBIT_TIA_DIRECTORY');

pest()->tia()->directory(is_string($tiaDirectory) && $tiaDirectory !== '' ? $tiaDirectory : dirname(__DIR__).'/.orbit-tia');

// The Gateway reads these as programs and catalogues, not as PHP, so coverage never records them.
// A change reruns the tests that cover that file, and no others.
pest()->tia()->watch([
    'resources/tasks/check' => 'tests/Feature/Infrastructure/Tasks/RemoteTaskCheckRunnerTest.php',
    'resources/tasks/metadata' => 'tests/Feature/Infrastructure/Tasks',
    'resources/tasks/turn' => 'tests/Feature/Tasks/TurnReceiptTest.php',
    'resources/tasks/actions.json' => 'tests/Feature/Tasks/TaskDefinitionValidationTest.php',
    'resources/mcp/tools.json' => 'tests/Feature/Mcp',
    'resources/scripts/*.py' => 'tests/Feature/Infrastructure/Metrics/ServiceMetricsProgramTest.php',
    'resources/proxycli/*.py' => 'tests/Unit/Infrastructure/ProxyCli/ProxyCliCollectorValkeyClientTest.php',
    'resources/instances/*.py' => 'tests/Feature/Domain/ProjectLifecycleRunnerTest.php',
    'resources/analytics/clickhouse/**/*.xml' => 'tests/Feature/Infrastructure/Analytics/NativeAnalyticsClickhouseConfigurationManagerTest.php',
]);

/** @param list<array{name: string, phase: string, command: string, timeout_seconds: int}> $steps */
function store_deploy_steps(Instance $instance, array $steps): void
{
    app(InstanceDeployStepStore::class)->replaceAll(
        $instance,
        array_map(static fn (array $step): DeploymentStep => new DeploymentStep(
            $step['name'],
            DeploymentPhase::from($step['phase']),
            $step['command'],
            $step['timeout_seconds'],
        ), $steps),
    );
}

/** @return list<array{name: string, phase: string, command: string, timeout_seconds: int}> */
function orbit_test_set_app_placement_role(Node $node, bool $production): void
{
    $role = $production ? RoleName::AppProd : RoleName::AppDev;

    if ($node->exists) {
        NodeRole::query()->updateOrCreate(
            ['node_id' => $node->id, 'role' => $role],
            ['status' => LifecycleStatus::Active],
        );

        return;
    }

    $node->setRelation('roles', collect([
        new NodeRole(['role' => $role, 'status' => LifecycleStatus::Active]),
    ]));
}

function normalized_deploy_steps(Instance $instance): array
{
    return array_map(
        static fn (DeploymentStep $step): array => $step->toArray(),
        app(InstanceDeployStepStore::class)->ordered($instance),
    );
}

function app_instance_deploy_step_records_migration(): object
{
    return require base_path(
        'database/migrations/2026_09_14_180000_store_deploy_steps_as_named_records.php',
    );
}

function app_instance_deployment_config_migration(): object
{
    return require base_path(
        'database/migrations/2026_09_11_000000_add_deployment_config_to_app_instances.php',
    );
}

/** @return array{Project, Node} */
function deployment_migration_parents(): array
{
    $count = Node::query()->count();
    $node = Node::query()->create([
        'name' => "deployment-migration-{$count}",
        'status' => 'active',
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.'.(130 + $count),
    ]);
    $project = Project::query()->create([
        'name' => "Deployment migration {$count}",
        'slug' => "deployment-migration-{$count}",
        'repository_url' => "https://example.test/deployment-migration-{$count}.git",
        'default_branch' => 'main',
        'root' => 'public',
    ]);

    return [$project, $node];
}

/** @return array{Node, Node, Project, Instance} */
function deployment_api_fixture(): array
{
    $caller = Node::query()->create([
        'name' => 'deployment-gateway-peer',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.140',
        'wireguard_ip' => '10.44.0.140',
        'user' => 'orbit',
    ]);
    $caller->roles()->create(['role' => RoleName::Gateway, 'status' => LifecycleStatus::Active]);
    $owner = Node::query()->create([
        'name' => 'deployment-owner',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.141',
        'wireguard_ip' => '10.44.0.141',
        'user' => 'orbit',
    ]);
    $owner->roles()->create(['role' => RoleName::AppProd, 'status' => LifecycleStatus::Active]);
    $project = Project::query()->create([
        'name' => 'Deployment API',
        'slug' => 'deployment-api',
        'repository_url' => 'https://example.test/deployment-api.git',
        'default_branch' => 'main',
        'root' => 'public',
    ]);
    $instance = Instance::query()->create([
        'project_id' => $project->id,
        'node_id' => $owner->id,
        'name' => 'production',
        'environment' => 'production',
        'checkout_path' => '/home/deployment-api/releases/initial',
        'production_user' => 'deployment-api',
        'production_home' => '/home/deployment-api',
        'branch' => 'main',
        'branch_override' => 'main',
        'status' => 'source_resolved',
    ]);

    return [$caller, $owner, $project, $instance->fresh()];
}

function orb183_production_route_migration(): Migration
{
    return require base_path(
        'database/migrations/2026_09_08_200000_enable_production_route_target_sets.php',
    );
}

function app_instance_removal_migration_boundary(): Migration
{
    return new class extends Migration
    {
        public function down(): void
        {
            run_legacy_schema_migration(orb183_production_route_migration(), 'down');
            run_legacy_schema_migration($this->removalMigration(), 'down');
        }

        public function up(): void
        {
            run_legacy_schema_migration($this->removalMigration(), 'up');
            run_legacy_schema_migration(orb183_production_route_migration(), 'up');
        }

        private function removalMigration(): Migration
        {
            return require base_path(
                'database/migrations/2026_09_08_000000_persist_app_instance_removal_inventory.php',
            );
        }
    };
}

/**
 * Returns the host variant for running a dependency update supervisor program in a test. Linux runs the exact
 * Node program. Other hosts, such as macOS, have no /proc, so they run the same program logic with the
 * Portable variant, which reads process state with ps.
 */
function dependency_update_supervisor_host(): DependencyUpdateSupervisorHost
{
    return PHP_OS_FAMILY === 'Linux' ? DependencyUpdateSupervisorHost::Node : DependencyUpdateSupervisorHost::Portable;
}

/**
 * Adapt a Caddyfile with the installed caddy binary, which must meet the release floor in
 * App\Domain\Nodes\CaddyRelease. Reads the configuration from a temporary file rather than stdin.
 */
function caddy_adapt(string $configuration): CommandResult
{
    $path = tempnam(sys_get_temp_dir(), 'orbit-caddy-adapt-');
    if ($path === false) {
        throw new RuntimeException('Could not create a temporary Caddyfile.');
    }
    try {
        file_put_contents($path, $configuration);

        return new NativeProcessRunner()->run(new ProcessInvocation(
            arguments: [TestToolchain::require('caddy', 'brew install caddy'), 'adapt', '--config', $path, '--adapter', 'caddyfile'],
        ));
    } finally {
        unlink($path);
    }
}

/**
 * @return array{Node, Node}
 */
function router_lan_topology(): array
{
    $cluster = Cluster::query()->create([
        'name' => 'lan-cluster',
        'state' => ClusterState::Active,
    ]);
    $router = router_lan_node('lan-router', '10.44.0.10', '10.20.0.10', $cluster);
    $eligible = router_lan_node('lan-member', '10.44.0.11', '10.20.0.11', $cluster);
    $router->roles()->create([
        'role' => RoleName::Router,
        'status' => LifecycleStatus::Active,
        'cluster_id' => $cluster->id,
    ]);

    return [$router->fresh('cluster') ?? $router, $eligible];
}

/** @return list<Node> */
function router_lan_denied_sources(Cluster $cluster): array
{
    $other = Cluster::query()->create([
        'name' => 'other-lan-cluster',
        'state' => ClusterState::Active,
    ]);

    return [
        router_lan_node('vpn-only', '10.44.0.12', null, $cluster),
        router_lan_node('inactive-member', '10.44.0.13', '10.20.0.13', $cluster, LifecycleStatus::Failed),
        router_lan_node('standalone', '10.44.0.14', '10.20.0.14'),
        router_lan_node('other-cluster', '10.44.0.15', '10.20.0.15', $other),
        router_lan_node('lan-only', '10.44.0.16', '10.20.0.16', $cluster, publicKey: null),
        router_lan_node('public-source', '10.44.0.17', '10.20.0.17'),
    ];
}

function router_lan_node(
    string $name,
    string $wireguardIp,
    ?string $lanIp,
    ?Cluster $cluster = null,
    LifecycleStatus $status = LifecycleStatus::Active,
    ?string $publicKey = 'wg-key',
): Node {
    return Node::query()->create([
        'name' => $name,
        'cluster_id' => $cluster?->id,
        'status' => $status,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.'.str_replace('10.44.0.', '', $wireguardIp),
        'public_ssh_port' => 22,
        'user' => 'orbit',
        'wireguard_ip' => $wireguardIp,
        'lan_ip' => $lanIp,
        'wireguard_public_key' => $publicKey,
    ]);
}
