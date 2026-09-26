<?php

declare(strict_types=1);

use App\Actions\Nodes\ProvisionNodeAction;
use App\Data\Nodes\ProvisionNodeData;
use App\Domain\AppDev\ClusterRouterDnsSelectionReconciler;
use App\Domain\AppDev\PrivateDnsManager;
use App\Domain\Metrics\MetricsFleetReconciler;
use App\Domain\Nodes\NodeConverger;
use App\Domain\Nodes\NodeObservation;
use App\Domain\Nodes\NodeProvisioningIdentity;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Tools\ToolManagerMaterializer;
use App\Domain\WireGuard\GatewayPeerProjectionManager;
use App\Infrastructure\AppDev\DnsmasqPrivateDnsManager;
use App\Infrastructure\AppDev\NativeClusterRouterDnsSelectionReconciler;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Processes\ProcessInvocation;
use App\Infrastructure\Processes\ProcessRunner;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Ssh\SshConnection;
use App\Infrastructure\Ssh\SshExecutor;
use App\Models\Node;
use Tests\Support\FakeToolManagerMaterializer;

it('publishes private DNS on the vpn node when node:add reprovisions a split gateway', function (): void {
    $processes = new ProvisionNodePrivateDnsProcessRunner;
    $ssh = new ProvisionNodePrivateDnsSshExecutor;
    app()->instance(ProcessRunner::class, $processes);
    app()->instance(SshExecutor::class, $ssh);
    app()->forgetInstance(DnsmasqPrivateDnsManager::class);
    app()->forgetInstance(PrivateDnsManager::class);
    app()->instance(
        ClusterRouterDnsSelectionReconciler::class,
        new NativeClusterRouterDnsSelectionReconciler(app(DnsmasqPrivateDnsManager::class)),
    );
    app()->instance(ToolManagerMaterializer::class, new FakeToolManagerMaterializer);
    app()->instance(GatewayPeerProjectionManager::class, new ProvisionNodePrivateDnsPeerProjection);
    app()->instance(NodeConverger::class, new class implements NodeConverger
    {
        public function converge(
            Node $node,
            NodeProvisioningIdentity $identity,
            ?string $expectedSshHostFingerprint = null,
            bool $rolelessOperator = false,
        ): NodeObservation {
            return new NodeObservation('x86_64');
        }
    });
    $metrics = Mockery::mock(MetricsFleetReconciler::class);
    $metrics->shouldReceive('reconcile')->once();
    app()->instance(MetricsFleetReconciler::class, $metrics);
    $vpn = Node::query()->create([
        'name' => 'vpn',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'architecture' => 'x86_64',
        'public_ssh_host' => 'vpn.example.test',
        'user' => 'orbit',
        'wireguard_ip' => '10.44.0.1',
        'ssh_host_fingerprint' => 'SHA256:pinned',
    ]);
    $vpn->roles()->create([
        'role' => RoleName::Vpn,
        'status' => LifecycleStatus::Active,
    ]);
    $gateway = Node::query()->create([
        'name' => 'gateway',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'architecture' => 'x86_64',
        'public_ssh_host' => 'gateway.example.test',
        'user' => 'orbit',
        'wireguard_ip' => '10.44.0.2',
        'ssh_host_fingerprint' => 'SHA256:pinned',
    ]);
    $gateway->roles()->create([
        'role' => RoleName::Gateway,
        'status' => LifecycleStatus::Active,
    ]);

    $node = app(ProvisionNodeAction::class)->execute(new ProvisionNodeData(
        name: $gateway->name,
        publicSshHost: $gateway->public_ssh_host,
    ));

    expect($node->status)->toBe(LifecycleStatus::Active)
        ->and($node->getAttribute('failed_step'))->toBeNull()
        ->and($node->getAttribute('error_code'))->toBeNull()
        ->and($processes->calls)->toBe(0)
        ->and($ssh->hosts)->toBe(['10.44.0.1', '10.44.0.1'])
        ->and($ssh->users)->toBe([$vpn->user, $vpn->user]);
});

final class ProvisionNodePrivateDnsProcessRunner implements ProcessRunner
{
    public int $calls = 0;

    public function run(ProcessInvocation $invocation): CommandResult
    {
        $this->calls++;

        return new CommandResult(127, '', 'dnsmasq: command not found', 1, false);
    }
}

final class ProvisionNodePrivateDnsSshExecutor implements SshExecutor
{
    /** @var list<string> */
    public array $hosts = [];

    /** @var list<string> */
    public array $users = [];

    public function execute(SshConnection $connection, RemoteCommand $command): CommandResult
    {
        $this->hosts[] = $connection->host;
        $this->users[] = $connection->user;

        return new CommandResult(0, '', '', 1, false);
    }
}

final class ProvisionNodePrivateDnsPeerProjection implements GatewayPeerProjectionManager
{
    public function converge(Node $node): void {}

    public function remove(Node $node): void {}

    public function restore(Node $node): void {}
}
