<?php

declare(strict_types=1);

use App\Domain\AppDev\PrivateDnsManager;
use App\Domain\Nodes\NodeConverger;
use App\Domain\Nodes\NodeProvisioningException;
use App\Domain\Nodes\NodeProvisioningIdentity;
use App\Domain\Nodes\RoleBaselineConverger;
use App\Domain\Tools\ToolManagerMaterializer;
use App\Infrastructure\Processes\CommandResult;
use App\Models\Cluster;
use App\Models\Node;
use App\Models\NodeRole;
use Tests\Support\FakeToolManagerMaterializer;

it('provisions the first peer from the gateway console', function (): void {
    app()->instance(ToolManagerMaterializer::class, new FakeToolManagerMaterializer);
    app()->instance(PrivateDnsManager::class, new class implements PrivateDnsManager {
        public function converge(?Node $pendingNode = null): void {}
    });
    app()->instance(NodeConverger::class, new class implements NodeConverger {
        public function converge(
            Node $node,
            NodeProvisioningIdentity $identity,
            ?string $expectedSshHostFingerprint = null,
            bool $rolelessOperator = false,
        ): void {}
    });
    app()->instance(RoleBaselineConverger::class, new class implements RoleBaselineConverger {
        public function converge(Node $node, NodeRole $assignment): void {}

        public function remove(Node $node, NodeRole $assignment, bool $purgeData): void {}

        public function removeUnreachable(Node $node, NodeRole $assignment): void {}
    });

    $cluster = Cluster::query()->create(['name' => 'development']);

    $this
        ->artisan('orbit:node-provision', [
            'name' => 'operator',
            'host' => '94.237.108.25',
            '--role' => ['app-dev'],
            '--architecture' => 'x86_64',
            '--tld' => '.Operator.Orbit',
            '--wireguard-address' => '10.44.0.2',
            '--wireguard-ip' => '10.44.0.2',
            '--cluster' => (string) $cluster->id,
            '--lan-ip' => '10.0.0.2',
            '--wireguard-endpoint' => '10.0.0.2:51820',
            '--dns-server' => '10.0.0.2',
            '--host-key-fingerprint' => 'SHA256:pinned',
        ])
        ->expectsOutputToContain('Node [operator] is active.')
        ->assertExitCode(0);

    $node = Node::query()->where('name', 'operator')->sole();

    expect($node->platform)
        ->toBe('linux')
        ->and($node->architecture)
        ->toBe('x86_64')
        ->and($node->tld)
        ->toBe('operator.orbit')
        ->and($node->cluster_id)
        ->toBe($cluster->id)
        ->and($node->wireguard_ip)
        ->toBe('10.44.0.2')
        ->and($node->wireguard_address)
        ->toBe('10.44.0.2')
        ->and($node->lan_ip)
        ->toBe('10.0.0.2');
});

it('rejects conflicting WireGuard options before provisioning', function (): void {
    $this
        ->artisan('orbit:node-provision', [
            'name' => 'conflict',
            'host' => '192.0.2.50',
            '--wireguard-ip' => '10.44.0.5',
            '--wireguard-address' => '10.44.0.6',
        ])
        ->expectsOutput('The WireGuard IP options conflict.')
        ->assertExitCode(1);

    expect(Node::query()->where('name', 'conflict')->exists())->toBeFalse();
});

it('reports typed provisioning failures without leaking command output', function (): void {
    app()->instance(NodeConverger::class, new class implements NodeConverger {
        public function converge(
            Node $node,
            NodeProvisioningIdentity $identity,
            ?string $expectedSshHostFingerprint = null,
            bool $rolelessOperator = false,
        ): void {
            throw new NodeProvisioningException(
                step: 'base-packages',
                errorCode: 'node.package_install_failed',
                message: 'sensitive command output',
                result: new CommandResult(1, 'sensitive stdout', 'sensitive stderr', 42, false),
            );
        }
    });

    $this
        ->artisan('orbit:node-provision', [
            'name' => 'operator',
            'host' => '94.237.108.25',
            '--architecture' => 'x86_64',
            '--host-key-fingerprint' => 'SHA256:pinned',
        ])
        ->expectsOutput('Node provisioning failed at step [base-packages] with error [node.package_install_failed].')
        ->doesntExpectOutputToContain('sensitive command output')
        ->doesntExpectOutputToContain('sensitive stdout')
        ->doesntExpectOutputToContain('sensitive stderr')
        ->assertExitCode(1);
});

it('passes explicit console user identities', function (): void {
    app()->instance(ToolManagerMaterializer::class, new FakeToolManagerMaterializer);
    app()->instance(PrivateDnsManager::class, new class implements PrivateDnsManager {
        public function converge(?Node $pendingNode = null): void {}
    });
    $identity = null;
    app()->instance(NodeConverger::class, new class($identity) implements NodeConverger {
        public function __construct(
            private ?NodeProvisioningIdentity &$identity,
        ) {}

        public function converge(
            Node $node,
            NodeProvisioningIdentity $identity,
            ?string $expectedSshHostFingerprint = null,
            bool $rolelessOperator = false,
        ): void {
            $this->identity = $identity;
        }
    });
    app()->instance(RoleBaselineConverger::class, new class implements RoleBaselineConverger {
        public function converge(Node $node, NodeRole $assignment): void {}

        public function remove(Node $node, NodeRole $assignment, bool $purgeData): void {}

        public function removeUnreachable(Node $node, NodeRole $assignment): void {}
    });
    $this->artisan('orbit:node-provision', [
        'name' => 'console-identity',
        'host' => '192.0.2.95',
        '--user' => 'deployer',
        '--orbit-user' => 'nckrtl',
        '--architecture' => 'x86_64',
        '--host-key-fingerprint' => 'SHA256:pinned',
    ])->assertExitCode(0);
    expect($identity?->bootstrapUser)->toBe('deployer')->and($identity?->managedUser)->toBe('nckrtl');
});
