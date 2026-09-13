<?php

declare(strict_types=1);

use App\Domain\AppDev\ClusterRouterDnsSelectionReconciler;
use App\Domain\Clusters\ClusterState;
use App\Domain\Firewall\RouterLanIngressReconciler;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Processes\NativeProcessRunner;
use App\Infrastructure\Processes\ProcessInvocation;
use App\Models\Cluster;
use App\Models\Node;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeClusterRouterDnsSelectionReconciler;
use Tests\Support\FakeRouterLanIngressReconciler;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class)
    ->beforeEach(function (): void {
        app()->instance(RouterLanIngressReconciler::class, new FakeRouterLanIngressReconciler);
        app()->instance(ClusterRouterDnsSelectionReconciler::class, new FakeClusterRouterDnsSelectionReconciler);
    })
    ->in('Feature');

pest()
    ->tia()
    ->locally()
    ->filtered();

$tiaDirectory = getenv('ORBIT_TIA_DIRECTORY');

if (is_string($tiaDirectory) && $tiaDirectory !== '') {
    pest()->tia()->directory($tiaDirectory);
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
            orb183_production_route_migration()->down();
            $this->removalMigration()->down();
        }

        public function up(): void
        {
            $this->removalMigration()->up();
            orb183_production_route_migration()->up();
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
 * Adapt a Caddyfile with the installed caddy binary. Reads the configuration
 * from a temporary file because Caddy 2.6 (Ubuntu 26.04) cannot read stdin.
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
            arguments: ['caddy', 'adapt', '--config', $path, '--adapter', 'caddyfile'],
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
