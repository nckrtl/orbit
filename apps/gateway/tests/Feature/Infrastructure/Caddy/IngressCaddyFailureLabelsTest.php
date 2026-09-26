<?php

declare(strict_types=1);

use App\Domain\AppDev\ClusterRouterDnsSelectionReconciler;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Infrastructure\Caddy\Build\NodeCaddyBuilds;
use App\Infrastructure\Nodes\CaddyPackageSourceProgram;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Ssh\SshConnection;
use App\Infrastructure\Ssh\SshExecutor;
use App\Models\Cluster;
use App\Models\Node;
use App\Models\NodeRole;
use Tests\Support\FakeClusterRouterDnsSelectionReconciler;
use Tests\Support\FakeNodeCaddyBuilds;

beforeEach(function (): void {
    $this->caller = $this->markAsGateway(caddy_failure_node('gateway-peer', '10.44.0.1'));
    $this->withServerVariables(['REMOTE_ADDR' => $this->caller->wireguard_ip]);
    app()->instance(SshExecutor::class, caddy_failure_ssh());
});

it('names ingress and ingress.caddy_config_failed when ingress convergence cannot order Caddy', function (): void {
    app()->instance(SshExecutor::class, caddy_failure_ssh('ordering'));
    $cluster = Cluster::query()->create(['name' => 'ingress-ordering']);
    $node = caddy_failure_node('edge', '10.44.0.2', $cluster);

    $this->postJson("/api/v1/nodes/{$node->id}/roles", ['role' => 'ingress'])
        ->assertStatus(502)
        ->assertJsonPath('error.code', 'node_role.convergence_failed')
        ->assertJsonPath('error.message', 'Ingress step [caddy-service-ordering] failed on node [edge].')
        ->assertJsonPath('error.details.step', 'converge:caddy-service-ordering')
        ->assertJsonPath('error.details.error_code', 'ingress.caddy_config_failed')
        ->assertJsonMissingPath('error.details.message');

    $assignment = $node->roles()->where('role', RoleName::Ingress)->sole();

    expect($assignment->status)->toBe(LifecycleStatus::Failed)
        ->and($assignment->failed_step)->toBe('converge:caddy-service-ordering')
        ->and($assignment->error_code)->toBe('ingress.caddy_config_failed');
});

it('keeps the Caddy build message and names ingress.caddy_config_failed when ingress convergence cannot build', function (): void {
    $cluster = Cluster::query()->create(['name' => 'ingress-build']);
    $node = caddy_failure_node('edge', '10.44.0.2', $cluster);
    caddy_failure_builds()->failNext($node->name, 'validate', 'Error: loading certificates');

    $this->postJson("/api/v1/nodes/{$node->id}/roles", ['role' => 'ingress'])
        ->assertStatus(502)
        ->assertJsonPath('error.code', 'node_role.convergence_failed')
        ->assertJsonPath('error.message', 'The Caddy build for Node [edge] failed at stage [validate]: Error: loading certificates')
        ->assertJsonPath('error.details.step', 'converge:caddy-config')
        ->assertJsonPath('error.details.error_code', 'ingress.caddy_config_failed')
        ->assertJsonPath('error.details.node', 'edge')
        ->assertJsonPath('error.details.stage', 'validate')
        ->assertJsonPath('error.details.message', 'Error: loading certificates');

    $assignment = $node->roles()->where('role', RoleName::Ingress)->sole();

    expect($assignment->failed_step)->toBe('converge:caddy-config')
        ->and($assignment->error_code)->toBe('ingress.caddy_config_failed');
});

it('returns details.error_code ingress.caddy_config_failed when ingress removal stops at remove:caddy-config', function (): void {
    $cluster = Cluster::query()->create(['name' => 'ingress-removal']);
    $node = caddy_failure_node('edge', '10.44.0.2', $cluster);
    $node->roles()->create([
        'role' => RoleName::Ingress,
        'cluster_id' => $cluster->id,
        'status' => LifecycleStatus::Active,
    ]);
    caddy_failure_builds()->failNext($node->name, 'validate', 'Error: loading certificates');

    $this->deleteJson("/api/v1/nodes/{$node->id}/roles/ingress", ['force' => true])
        ->assertStatus(502)
        ->assertJsonPath('error.code', 'node_role.remove_failed')
        ->assertJsonPath(
            'error.message',
            'The Caddy build for Node [edge] failed at stage [validate]: Error: loading certificates Retry with --offline if node [edge] is unreachable.',
        )
        ->assertJsonPath('error.details.step', 'remove:caddy-config')
        ->assertJsonPath('error.details.error_code', 'ingress.caddy_config_failed')
        ->assertJsonPath('error.details.node', 'edge')
        ->assertJsonPath('error.details.stage', 'validate')
        ->assertJsonPath('error.details.message', 'Error: loading certificates');

    $assignment = $node->roles()->where('role', RoleName::Ingress)->sole();

    expect($assignment->status)->toBe(LifecycleStatus::Failed)
        ->and($assignment->failed_step)->toBe('remove:caddy-config')
        ->and($assignment->error_code)->toBe('ingress.caddy_config_failed');
});

it('names the router and router.caddy_config_failed when router convergence cannot order Caddy', function (): void {
    app()->instance(SshExecutor::class, caddy_failure_ssh('ordering'));
    $cluster = Cluster::query()->create(['name' => 'router-ordering']);
    $node = caddy_failure_node('router-a', '10.44.0.4', $cluster);

    $this->putJson("/api/v1/clusters/{$cluster->id}/router/{$node->id}")
        ->assertStatus(502)
        ->assertJsonPath('error.code', 'router.caddy_config_failed')
        ->assertJsonPath('error.message', 'Router step [caddy-service-ordering] failed on node [router-a].')
        ->assertJsonPath('error.details.step', 'caddy-service-ordering');

    expect($node->roles()->where('role', RoleName::Router)->exists())->toBeFalse();
});

it('returns details.error_code router.caddy_config_failed when router removal stops at remove:caddy-config', function (): void {
    app()->instance(ClusterRouterDnsSelectionReconciler::class, new FakeClusterRouterDnsSelectionReconciler);
    $cluster = Cluster::query()->create(['name' => 'router-removal']);
    $node = caddy_failure_node('router-a', '10.44.0.4', $cluster);
    $node->roles()->create([
        'role' => RoleName::Router,
        'cluster_id' => $cluster->id,
        'status' => LifecycleStatus::Active,
    ]);
    caddy_failure_builds()->failNext($node->name, 'reload', 'Error: loading certificates');

    $this->deleteJson("/api/v1/clusters/{$cluster->id}/router", ['force' => true])
        ->assertStatus(502)
        ->assertJsonPath('error.code', 'node_role.remove_failed')
        ->assertJsonPath('error.message', 'The Caddy build for Node [router-a] failed at stage [reload]: Error: loading certificates')
        ->assertJsonPath('error.details.step', 'remove:caddy-config')
        ->assertJsonPath('error.details.error_code', 'router.caddy_config_failed')
        ->assertJsonPath('error.details.node', 'router-a')
        ->assertJsonPath('error.details.stage', 'reload')
        ->assertJsonPath('error.details.message', 'Error: loading certificates');

    $assignment = NodeRole::query()->where('node_id', $node->id)->where('role', RoleName::Router)->sole();

    expect($assignment->status)->toBe(LifecycleStatus::Failed)
        ->and($assignment->failed_step)->toBe('remove:caddy-config')
        ->and($assignment->error_code)->toBe('router.caddy_config_failed');
});

it('keeps an app-dev removal that stops in the Caddy build on app-dev.caddy_config_failed', function (): void {
    $node = caddy_failure_node('app-host', '10.44.0.5');
    $node->roles()->create([
        'role' => RoleName::AppDev,
        'status' => LifecycleStatus::Active,
    ]);
    caddy_failure_builds()->failNext($node->name, 'validate', 'Error: adapting config');

    $this->deleteJson("/api/v1/nodes/{$node->id}/roles/app-dev", ['force' => true])
        ->assertStatus(502)
        ->assertJsonPath('error.code', 'node_role.remove_failed')
        ->assertJsonPath('error.message', 'The Caddy build for Node [app-host] failed at stage [validate]: Error: adapting config Retry with --offline if node [app-host] is unreachable.')
        ->assertJsonPath('error.details.step', 'remove:caddy-config')
        ->assertJsonPath('error.details.error_code', 'app-dev.caddy_config_failed')
        ->assertJsonPath('error.details.message', 'Error: adapting config');

    expect($node->roles()->where('role', RoleName::AppDev)->sole()->error_code)->toBe('app-dev.caddy_config_failed');
});

it('names an ingress prerequisite failure as an ingress step and keeps its own code', function (): void {
    app()->instance(SshExecutor::class, caddy_failure_ssh('source'));
    $cluster = Cluster::query()->create(['name' => 'ingress-source']);
    $node = caddy_failure_node('edge', '10.44.0.2', $cluster);

    $this->postJson("/api/v1/nodes/{$node->id}/roles", ['role' => 'ingress'])
        ->assertStatus(502)
        ->assertJsonPath('error.code', 'node_role.convergence_failed')
        ->assertJsonPath('error.message', 'Ingress step [caddy-package-source] failed on node [edge].')
        ->assertJsonPath('error.details.step', 'converge:caddy-package-source')
        ->assertJsonPath('error.details.error_code', 'ingress.prerequisite_failed');
});

function caddy_failure_node(string $name, string $ip, ?Cluster $cluster = null): Node
{
    return Node::query()->create([
        'name' => $name,
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => $name.'.example.test',
        'user' => 'orbit',
        'wireguard_ip' => $ip,
        'cluster_id' => $cluster?->id,
    ]);
}

function caddy_failure_builds(): FakeNodeCaddyBuilds
{
    $builds = app(NodeCaddyBuilds::class);
    expect($builds)->toBeInstanceOf(FakeNodeCaddyBuilds::class);

    return $builds;
}

function caddy_failure_ssh(?string $fail = null): SshExecutor
{
    return new class($fail) implements SshExecutor
    {
        public function __construct(private ?string $fail) {}

        public function execute(SshConnection $connection, RemoteCommand $command): CommandResult
        {
            if (($command->arguments[0] ?? '') === 'sh') {
                return new CommandResult(0, "orbit\n/home/orbit\norbit\n", '', 1, false);
            }

            $ordering = ($command->arguments[4] ?? null) === 'caddy'
                && str_contains($command->input ?? '', 'orbit-vpn.conf');
            $source = in_array(CaddyPackageSourceProgram::SOURCE_URI, $command->arguments, true);

            if (($this->fail === 'ordering' && $ordering) || ($this->fail === 'source' && $source)) {
                return new CommandResult(1, '', 'forced failure', 1, false);
            }

            return new CommandResult(0, '', '', 1, false);
        }
    };
}
