<?php

declare(strict_types=1);

use App\Domain\AppDev\PrivateDnsManager;
use App\Domain\Nodes\NodeConverger;
use App\Domain\Nodes\NodeProvisioningException;
use App\Domain\Nodes\RoleBaselineConverger;
use App\Infrastructure\Processes\CommandResult;
use App\Models\Node;
use App\Models\NodeRole;

it('provisions the first peer from the gateway console', function (): void {
    app()->instance(PrivateDnsManager::class, new class implements PrivateDnsManager {
        public function converge(?Node $pendingNode = null): void {}
    });
    app()->instance(NodeConverger::class, new class implements NodeConverger {
        public function converge(Node $node, ?string $expectedSshHostFingerprint = null): void {}
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
});

it('reports typed provisioning failures without leaking command output', function (): void {
    app()->instance(NodeConverger::class, new class implements NodeConverger {
        public function converge(Node $node, ?string $expectedSshHostFingerprint = null): void
        {
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
