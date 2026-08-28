<?php

declare(strict_types=1);

use App\Domain\AppDev\PrivateDnsManager;
use App\Domain\Nodes\NodeConverger;
use App\Domain\Nodes\NodeProvisioningIdentity;
use App\Domain\Nodes\RoleBaselineConverger;
use App\Domain\Tools\ToolManagerMaterializer;
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
        ): void {}
    });
    app()->instance(RoleBaselineConverger::class, new class implements RoleBaselineConverger {
        public function converge(Node $node, NodeRole $assignment): void {}

        public function remove(Node $node, NodeRole $assignment, bool $purgeData): void {}
    });

    $this
        ->artisan('orbit:node-provision', [
            'name' => 'operator',
            'host' => '94.237.108.25',
            '--role' => ['app-dev'],
            '--architecture' => 'x86_64',
            '--tld' => '.Operator.Orbit',
            '--wireguard-address' => '10.44.0.2',
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
        ->toBe('operator.orbit');
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
            ): void {
                $this->identity = $identity;
            }
        });
        app()->instance(RoleBaselineConverger::class, new class implements RoleBaselineConverger {
            public function converge(Node $node, NodeRole $assignment): void {}

            public function remove(Node $node, NodeRole $assignment, bool $purgeData): void {}
        });
        $this->artisan('orbit:node-provision', [
            'name' => 'console-identity',
            'host' => '192.0.2.95',
            '--user' => 'nckrtl',
            '--orbit-user' => 'nckrtl',
            '--architecture' => 'x86_64',
            '--host-key-fingerprint' => 'SHA256:pinned',
        ])->assertExitCode(0);
        expect($identity?->bootstrapUser)->toBe('nckrtl')->and($identity?->managedUser)->toBe('nckrtl');
    });
});
