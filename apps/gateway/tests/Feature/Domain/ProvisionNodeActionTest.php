<?php

declare(strict_types=1);

use App\Actions\Nodes\ProvisionNodeAction;
use App\Data\Nodes\ProvisionNodeData;
use App\Domain\AppDev\AppDevTldConverger;
use App\Domain\AppDev\RuntimeConvergenceException;
use App\Domain\Metrics\MetricsFleetReconciler;
use App\Domain\Nodes\NodeConverger;
use App\Domain\Nodes\NodeProvisioningException;
use App\Domain\Nodes\NodeProvisioningIdentity;
use App\Domain\Nodes\NodeProvisioningLock;
use App\Domain\Nodes\NodeProvisioningLockException;
use App\Domain\Nodes\NodeRoleOperationException;
use App\Domain\Nodes\RecoverableNodeConverger;
use App\Domain\Nodes\RoleAssignmentException;
use App\Domain\Nodes\RoleBaselineConverger;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tools\ToolManagerMaterializer;
use App\Domain\Tools\ToolManagerName;
use App\Domain\WireGuard\GatewayPeerProjectionManager;
use App\Infrastructure\Processes\CommandResult;
use App\Models\App;
use App\Models\Node;
use App\Models\NodeRole;
use App\Models\ToolManagerRecord;
use Illuminate\Support\Facades\DB;
use Tests\Support\FakeToolManagerMaterializer;

/** @mago-expect lint:halstead The provisioning group keeps ordering and failure boundaries visible. */
describe(ProvisionNodeAction::class, function (): void {
    beforeEach(function (): void {
        app()->instance(ToolManagerMaterializer::class, new FakeToolManagerMaterializer);
        app()->instance(RoleBaselineConverger::class, new class implements RoleBaselineConverger {
            public function converge(Node $node, NodeRole $assignment): void {}

            public function remove(Node $node, NodeRole $assignment, bool $purgeData): void {}

            public function removeUnreachable(Node $node, NodeRole $assignment): void {}
        });
    });

    it('reconciles a roleless provisioned node after activation', function (): void {
        app()->instance(NodeConverger::class, new class implements NodeConverger {
            public function converge(
                Node $node,
                NodeProvisioningIdentity $identity,
                ?string $expectedSshHostFingerprint = null,
                bool $rolelessOperator = false,
            ): void {}
        });
        $metrics = Mockery::mock(MetricsFleetReconciler::class);
        $metrics->shouldReceive('reconcile')->once()->withNoArgs();
        app()->instance(MetricsFleetReconciler::class, $metrics);

        $node = app(ProvisionNodeAction::class)->execute(new ProvisionNodeData(
            name: 'roleless-exporter',
            publicSshHost: '192.0.2.97',
            architecture: 'x86_64',
            expectedSshHostFingerprint: 'SHA256:pinned',
        ));

        expect($node->status)->toBe(LifecycleStatus::Active);
    });

    it('reconciles a role-bearing provisioned node after activation', function (): void {
        app()->instance(NodeConverger::class, new class implements NodeConverger {
            public function converge(
                Node $node,
                NodeProvisioningIdentity $identity,
                ?string $expectedSshHostFingerprint = null,
                bool $rolelessOperator = false,
            ): void {}
        });
        // Role convergence runs while the node is still provisioning and
        // exporter selection only sees active nodes, so without this call the
        // node goes active with no exporter and no Prometheus target.
        $metrics = Mockery::mock(MetricsFleetReconciler::class);
        $metrics->shouldReceive('reconcile')->once()->withNoArgs();
        app()->instance(MetricsFleetReconciler::class, $metrics);

        $node = app(ProvisionNodeAction::class)->execute(new ProvisionNodeData(
            name: 'role-bearing-exporter',
            publicSshHost: '192.0.2.99',
            architecture: 'x86_64',
            expectedSshHostFingerprint: 'SHA256:pinned',
            roles: [RoleName::AppProd],
        ));

        expect($node->status)
            ->toBe(LifecycleStatus::Active)
            ->and($node->roles->pluck('role')->all())
            ->toBe([RoleName::AppProd]);
    });

    it('uses the node provisioning failure boundary when roleless Metrics reconciliation fails', function (): void {
        app()->instance(NodeConverger::class, new class implements NodeConverger {
            public function converge(
                Node $node,
                NodeProvisioningIdentity $identity,
                ?string $expectedSshHostFingerprint = null,
                bool $rolelessOperator = false,
            ): void {}
        });
        $metrics = Mockery::mock(MetricsFleetReconciler::class);
        $metrics->shouldReceive('reconcile')->once()->andThrow(new RuntimeException('metrics failure'));
        app()->instance(MetricsFleetReconciler::class, $metrics);

        expect(fn () => app(ProvisionNodeAction::class)->execute(new ProvisionNodeData(
            name: 'roleless-exporter-failure',
            publicSshHost: '192.0.2.98',
            architecture: 'x86_64',
            expectedSshHostFingerprint: 'SHA256:pinned',
        )))
            ->toThrow(NodeProvisioningException::class, 'Metrics fleet reconciliation failed.');

        $node = Node::query()->where('name', 'roleless-exporter-failure')->sole();
        expect($node->status)
            ->toBe(LifecycleStatus::Failed)
            ->and($node->failed_step)
            ->toBe('metrics-exporters')
            ->and($node->error_code)
            ->toBe('node.metrics_reconcile_failed');
    });

    it('rejects an invalid stored managed user before mutating an existing node', function (): void {
        $existing = Node::query()->create([
            'name' => 'corrupt-managed-user',
            'status' => LifecycleStatus::Failed,
            'platform' => 'linux',
            'architecture' => 'x86_64',
            'public_ssh_host' => '192.0.2.71',
            'wireguard_address' => '10.44.0.71',
            'user' => 'invalid user',
        ]);
        $converged = false;
        app()->instance(NodeConverger::class, new class($converged) implements NodeConverger {
            public function __construct(
                private bool &$converged,
            ) {}

            public function converge(
                Node $node,
                NodeProvisioningIdentity $identity,
                ?string $expectedSshHostFingerprint = null,
                bool $rolelessOperator = false,
            ): void {
                $this->converged = true;
            }
        });

        expect(fn () => app(ProvisionNodeAction::class)->execute(new ProvisionNodeData(
            name: $existing->name,
            publicSshHost: $existing->public_ssh_host,
            expectedSshHostFingerprint: 'SHA256:pinned',
        )))
            ->toThrow(
                fn (ResourceOperationException $exception): bool => (
                    $exception->errorCode === 'node.invalid_linux_user'
                    && $exception->status === 422
                ),
            );

        expect($converged)
            ->toBeFalse()
            ->and($existing->refresh()->user)
            ->toBe('invalid user')
            ->and($existing->status)
            ->toBe(LifecycleStatus::Failed);
    });

    it('rejects provisioning contention before creating or changing a node', function (): void {
        app()->instance(NodeProvisioningLock::class, new class implements NodeProvisioningLock {
            public function run(string $nodeName, Closure $callback): mixed
            {
                throw new NodeProvisioningLockException($nodeName);
            }
        });

        expect(fn () => app(ProvisionNodeAction::class)->execute(new ProvisionNodeData(
            name: 'busy-node',
            publicSshHost: '192.0.2.70',
            architecture: 'x86_64',
            expectedSshHostFingerprint: 'SHA256:pinned',
        )))->toThrow(function (ResourceOperationException $exception): void {
            expect($exception->errorCode)->toBe('node.provisioning_busy')->and($exception->status)->toBe(409);
        });

        expect(Node::query()->where('name', 'busy-node')->exists())->toBeFalse();
    });

    it('restores an active node and its gateway peer when reprovisioning fails after replacing its key', function (): void {
        $projection = new class implements GatewayPeerProjectionManager {
            /** @var list<string|null> */
            public array $keys = [];

            public function converge(Node $node): void
            {
                $this->keys[] = $node->wireguard_public_key;
            }

            public function remove(Node $node): void {}

            public function restore(Node $node): void
            {
                $this->keys[] = $node->wireguard_public_key;
            }
        };
        app()->instance(GatewayPeerProjectionManager::class, $projection);
        app()->instance(NodeConverger::class, new class implements NodeConverger {
            public function converge(
                Node $node,
                NodeProvisioningIdentity $identity,
                ?string $expectedSshHostFingerprint = null,
                bool $rolelessOperator = false,
            ): void {
                $node->update(['wireguard_public_key' => 'replacement-key']);
                app(GatewayPeerProjectionManager::class)->converge($node);

                throw new NodeProvisioningException('wireguard', 'node.wireguard_failed', 'WireGuard failed.');
            }
        });
        $existing = Node::query()->create([
            'name' => 'active-reprovision',
            'status' => LifecycleStatus::Active,
            'platform' => 'linux',
            'architecture' => 'x86_64',
            'public_ssh_host' => '192.0.2.40',
            'wireguard_address' => '10.44.0.40',
            'wireguard_public_key' => 'prior-key',
            'ssh_host_fingerprint' => 'SHA256:pinned',
        ]);

        expect(fn () => app(ProvisionNodeAction::class)->execute(new ProvisionNodeData(
            name: $existing->name,
            publicSshHost: $existing->public_ssh_host,
        )))
            ->toThrow(
                fn (NodeProvisioningException $exception) => (
                    $exception->step === 'wireguard'
                    && $exception->errorCode === 'node.wireguard_failed'
                    && $exception->getMessage() === 'WireGuard failed.'
                ),
            );

        $existing->refresh();
        expect($existing->status)
            ->toBe(LifecycleStatus::Active)
            ->and($existing->getAttribute('failed_step'))
            ->toBeNull()
            ->and($existing->getAttribute('error_code'))
            ->toBeNull()
            ->and($existing->wireguard_public_key)
            ->toBe('prior-key')
            ->and($projection->keys)
            ->toBe(['replacement-key', 'prior-key']);
    });

    it('returns a bounded rollback failure when gateway peer restoration fails', function (): void {
        app()->instance(GatewayPeerProjectionManager::class, new class implements GatewayPeerProjectionManager {
            public function converge(Node $node): void {}

            public function remove(Node $node): void {}

            public function restore(Node $node): void
            {
                throw new RuntimeException('secret host output');
            }
        });
        app()->instance(NodeConverger::class, new class implements NodeConverger {
            public function converge(
                Node $node,
                NodeProvisioningIdentity $identity,
                ?string $expectedSshHostFingerprint = null,
                bool $rolelessOperator = false,
            ): void {
                $node->update(['wireguard_public_key' => 'replacement-key']);

                throw new NodeProvisioningException('wireguard', 'node.wireguard_failed', 'WireGuard failed.');
            }
        });
        $existing = Node::query()->create([
            'name' => 'rollback-failure',
            'status' => LifecycleStatus::Active,
            'platform' => 'linux',
            'architecture' => 'x86_64',
            'public_ssh_host' => '192.0.2.41',
            'wireguard_address' => '10.44.0.41',
            'wireguard_public_key' => 'prior-key',
            'ssh_host_fingerprint' => 'SHA256:pinned',
        ]);

        expect(fn () => app(ProvisionNodeAction::class)->execute(new ProvisionNodeData(
            name: $existing->name,
            publicSshHost: $existing->public_ssh_host,
        )))
            ->toThrow(
                fn (NodeProvisioningException $exception) => (
                    $exception->step === 'node-rollback'
                    && $exception->errorCode === 'node.reprovision_rollback_failed'
                    && ! str_contains($exception->getMessage(), 'secret host output')
                ),
            );

        expect($existing->refresh()->status)
            ->toBe(LifecycleStatus::Active)
            ->and($existing->wireguard_public_key)
            ->toBe('prior-key');
    });

    it('rolls back an active node when APT materialization fails after base convergence', function (): void {
        $projection = new class implements GatewayPeerProjectionManager {
            /** @var list<string|null> */
            public array $keys = [];

            public function converge(Node $node): void
            {
                $this->keys[] = $node->wireguard_public_key;
            }

            public function remove(Node $node): void {}

            public function restore(Node $node): void
            {
                $this->keys[] = $node->wireguard_public_key;
            }
        };
        app()->instance(GatewayPeerProjectionManager::class, $projection);
        app()->instance(NodeConverger::class, new class implements NodeConverger {
            public function converge(
                Node $node,
                NodeProvisioningIdentity $identity,
                ?string $expectedSshHostFingerprint = null,
                bool $rolelessOperator = false,
            ): void {
                $node->update(['wireguard_public_key' => 'replacement-key']);
                app(GatewayPeerProjectionManager::class)->converge($node);
            }
        });
        $materializer = new FakeToolManagerMaterializer;
        $materializer->failure = new NodeProvisioningException('tool-manager-apt', 'node.apt_failed', 'APT failed.');
        app()->instance(ToolManagerMaterializer::class, $materializer);
        $existing = Node::query()->create([
            'name' => 'apt-rollback',
            'status' => LifecycleStatus::Active,
            'platform' => 'linux',
            'architecture' => 'x86_64',
            'public_ssh_host' => '192.0.2.42',
            'wireguard_address' => '10.44.0.42',
            'wireguard_public_key' => 'prior-key',
            'ssh_host_fingerprint' => 'SHA256:pinned',
            'failed_step' => null,
            'error_code' => null,
        ]);

        expect(fn () => app(ProvisionNodeAction::class)->execute(new ProvisionNodeData(
            name: $existing->name,
            publicSshHost: $existing->public_ssh_host,
        )))
            ->toThrow(
                fn (NodeProvisioningException $exception) => (
                    $exception->step === 'tool-manager-apt'
                    && $exception->errorCode === 'node.apt_failed'
                    && $exception->getMessage() === 'APT failed.'
                ),
            );

        expect($existing->refresh()->status)
            ->toBe(LifecycleStatus::Active)
            ->and($existing->failed_step)
            ->toBeNull()
            ->and($existing->error_code)
            ->toBeNull()
            ->and($existing->wireguard_public_key)
            ->toBe('prior-key')
            ->and($projection->keys)
            ->toBe(['replacement-key', 'prior-key']);
    });

    it('rolls back the remote peer before restoring persisted state and the gateway projection after APT failure', function (): void {
        $events = [];
        $projection = new class($events) implements GatewayPeerProjectionManager {
            public function __construct(
                private array &$events,
            ) {}

            public function converge(Node $node): void
            {
                $this->events[] = "gateway-converge:{$node->wireguard_public_key}";
            }

            public function remove(Node $node): void {}

            public function restore(Node $node): void
            {
                $this->events[] = "gateway-restore:{$node->wireguard_public_key}";
            }
        };
        app()->instance(GatewayPeerProjectionManager::class, $projection);
        app()->instance(NodeConverger::class, new class($events) implements NodeConverger, RecoverableNodeConverger {
            public function __construct(
                private array &$events,
            ) {}

            public function converge(
                Node $node,
                NodeProvisioningIdentity $identity,
                ?string $expectedSshHostFingerprint = null,
                bool $rolelessOperator = false,
            ): void {
                $this->events[] = 'ordinary-converge';
            }

            public function convergeRecoverably(
                Node $node,
                NodeProvisioningIdentity $identity,
                ?string $expectedSshHostFingerprint,
                Closure $completion,
                bool $rolelessOperator = false,
            ): void {
                $node->update(['wireguard_public_key' => 'replacement-key']);
                app(GatewayPeerProjectionManager::class)->converge($node);

                try {
                    $completion();
                } catch (Throwable $throwable) {
                    $this->events[] = 'remote-rollback';

                    throw $throwable;
                }
            }
        });
        $materializer = new FakeToolManagerMaterializer;
        $materializer->failure = new NodeProvisioningException('tool-manager-apt', 'node.apt_failed', 'APT failed.');
        app()->instance(ToolManagerMaterializer::class, $materializer);
        $existing = Node::query()->create([
            'name' => 'apt-rollback-order',
            'status' => LifecycleStatus::Active,
            'platform' => 'linux',
            'architecture' => 'x86_64',
            'public_ssh_host' => '192.0.2.43',
            'wireguard_address' => '10.44.0.43',
            'wireguard_public_key' => 'prior-key',
            'ssh_host_fingerprint' => 'SHA256:pinned',
        ]);

        expect(fn () => app(ProvisionNodeAction::class)->execute(new ProvisionNodeData(
            name: $existing->name,
            publicSshHost: $existing->public_ssh_host,
        )))
            ->toThrow(NodeProvisioningException::class);

        expect($events)
            ->toBe([
                'gateway-converge:replacement-key',
                'remote-rollback',
                'gateway-restore:prior-key',
            ]);
    });

    it('marks rollback failure after restoring persisted state when recoverable remote rollback fails', function (): void {
        app()->instance(GatewayPeerProjectionManager::class, new class implements GatewayPeerProjectionManager {
            public function converge(Node $node): void {}

            public function remove(Node $node): void {}

            public function restore(Node $node): void {}
        });
        app()->instance(NodeConverger::class, new class implements NodeConverger, RecoverableNodeConverger {
            public function converge(
                Node $node,
                NodeProvisioningIdentity $identity,
                ?string $expectedSshHostFingerprint = null,
                bool $rolelessOperator = false,
            ): void {}

            public function convergeRecoverably(
                Node $node,
                NodeProvisioningIdentity $identity,
                ?string $expectedSshHostFingerprint,
                Closure $completion,
                bool $rolelessOperator = false,
            ): void {
                $node->update(['wireguard_public_key' => 'replacement-key']);
                app(GatewayPeerProjectionManager::class)->converge($node);

                try {
                    $completion();
                } catch (Throwable $throwable) {
                    throw new NodeProvisioningException(
                        step: 'wireguard-rollback',
                        errorCode: 'vpn.peer_rollback_failed',
                        message: 'Could not restore the remote WireGuard peer.',
                        previous: $throwable,
                    );
                }
            }
        });
        $materializer = new FakeToolManagerMaterializer;
        $materializer->failure = new NodeProvisioningException('tool-manager-apt', 'node.apt_failed', 'APT failed.');
        app()->instance(ToolManagerMaterializer::class, $materializer);
        $existing = Node::query()->create([
            'name' => 'remote-rollback-failure',
            'status' => LifecycleStatus::Active,
            'platform' => 'linux',
            'architecture' => 'x86_64',
            'public_ssh_host' => '192.0.2.44',
            'wireguard_address' => '10.44.0.44',
            'wireguard_public_key' => 'prior-key',
            'ssh_host_fingerprint' => 'SHA256:pinned',
        ]);

        expect(fn () => app(ProvisionNodeAction::class)->execute(new ProvisionNodeData(
            name: $existing->name,
            publicSshHost: $existing->public_ssh_host,
        )))
            ->toThrow(
                fn (NodeProvisioningException $exception) => (
                    $exception->step === 'node-rollback'
                    && $exception->errorCode === 'node.reprovision_rollback_failed'
                    && ! str_contains($exception->getMessage(), 'remote WireGuard peer')
                ),
            );

        expect($existing->refresh()->wireguard_public_key)->toBe('prior-key');
    });

    it('restores changed connection fields before rebuilding the prior gateway peer', function (): void {
        $projection = new class implements GatewayPeerProjectionManager {
            public array $peers = [];

            public function converge(Node $node): void
            {
                $this->peers[] = [$node->wireguard_address, $node->wireguard_public_key];
            }

            public function remove(Node $node): void {}

            public function restore(Node $node): void
            {
                $this->peers[] = [$node->wireguard_address, $node->wireguard_public_key];
            }
        };
        app()->instance(GatewayPeerProjectionManager::class, $projection);
        app()->instance(NodeConverger::class, new class implements NodeConverger {
            public function converge(
                Node $node,
                NodeProvisioningIdentity $identity,
                ?string $expectedSshHostFingerprint = null,
                bool $rolelessOperator = false,
            ): void {
                $node->update([
                    'ssh_host_key_type' => 'ecdsa',
                    'ssh_host_key' => 'replacement-host-key',
                    'ssh_host_fingerprint' => 'replacement-fingerprint',
                    'wireguard_public_key' => 'replacement-key',
                ]);
                app(GatewayPeerProjectionManager::class)->converge($node);
                throw new NodeProvisioningException('wireguard', 'node.wireguard_failed', 'WireGuard failed.');
            }
        });
        $existing = Node::query()->create([
            'name' => 'full-state-rollback',
            'status' => LifecycleStatus::Active,
            'platform' => 'linux',
            'architecture' => 'x86_64',
            'tld' => 'prior.orbit',
            'public_ssh_host' => '192.0.2.50',
            'public_ssh_port' => 2222,
            'user' => 'prior-user',
            'wireguard_address' => '10.44.0.50',
            'wireguard_endpoint_override' => '10.0.0.2:51820',
            'dns_server_override' => '10.0.0.1',
            'wireguard_public_key' => 'prior-key',
            'ssh_host_key_type' => 'ed25519',
            'ssh_host_key' => 'prior-host-key',
            'ssh_host_fingerprint' => 'prior-fingerprint',
            'failed_step' => 'prior-step',
            'error_code' => 'prior.error',
        ]);
        expect(fn () => app(ProvisionNodeAction::class)->execute(new ProvisionNodeData(
            name: $existing->name,
            publicSshHost: '192.0.2.51',
            tld: 'changed.orbit',
            wireguardAddress: '10.44.0.51',
            wireguardEndpointOverride: '10.0.0.3:51820',
            dnsServerOverride: '10.0.0.2',
        )))
            ->toThrow(NodeProvisioningException::class);
        expect($existing
            ->refresh()
            ->only([
                'status',
                'tld',
                'public_ssh_host',
                'public_ssh_port',
                'user',
                'wireguard_address',
                'wireguard_endpoint_override',
                'dns_server_override',
                'ssh_host_key_type',
                'ssh_host_key',
                'ssh_host_fingerprint',
                'wireguard_public_key',
                'failed_step',
                'error_code',
            ]))
            ->toBe([
                'status' => LifecycleStatus::Active,
                'tld' => 'prior.orbit',
                'public_ssh_host' => '192.0.2.50',
                'public_ssh_port' => 2222,
                'user' => 'prior-user',
                'wireguard_address' => '10.44.0.50',
                'wireguard_endpoint_override' => '10.0.0.2:51820',
                'dns_server_override' => '10.0.0.1',
                'ssh_host_key_type' => 'ed25519',
                'ssh_host_key' => 'prior-host-key',
                'ssh_host_fingerprint' => 'prior-fingerprint',
                'wireguard_public_key' => 'prior-key',
                'failed_step' => 'prior-step',
                'error_code' => 'prior.error',
            ])
            ->and($projection->peers)
            ->toBe([
                ['10.44.0.51', 'replacement-key'],
                ['10.44.0.50', 'prior-key'],
            ]);
    });

    it('materializes only APT after base convergence and before activation', function (): void {
        $materializer = new FakeToolManagerMaterializer;
        app()->instance(ToolManagerMaterializer::class, $materializer);
        app()->instance(NodeConverger::class, new class($materializer) implements NodeConverger {
            public function __construct(
                private FakeToolManagerMaterializer $materializer,
            ) {}

            public function converge(
                Node $node,
                NodeProvisioningIdentity $identity,
                ?string $expectedSshHostFingerprint = null,
                bool $rolelessOperator = false,
            ): void {
                $this->materializer->events[] = "base:{$node->status->value}";
            }
        });

        $node = app(ProvisionNodeAction::class)->execute(new ProvisionNodeData(
            name: 'apt-host',
            publicSshHost: '192.0.2.91',
            architecture: 'x86_64',
            expectedSshHostFingerprint: 'SHA256:pinned',
        ));

        expect($materializer->events)
            ->toBe(['base:provisioning', 'apt:provisioning'])
            ->and($materializer->requests)
            ->toBe([[ToolManagerName::Apt]])
            ->and($node->status)
            ->toBe(LifecycleStatus::Active);
    });

    it('uses recoverable convergence only for previously active nodes', function (): void {
        $events = [];
        app()->instance(NodeConverger::class, new class($events) implements NodeConverger, RecoverableNodeConverger {
            public function __construct(
                private array &$events,
            ) {}

            public function converge(
                Node $node,
                NodeProvisioningIdentity $identity,
                ?string $expectedSshHostFingerprint = null,
                bool $rolelessOperator = false,
            ): void {
                $this->events[] = "ordinary:{$node->name}";
            }

            public function convergeRecoverably(
                Node $node,
                NodeProvisioningIdentity $identity,
                ?string $expectedSshHostFingerprint,
                Closure $completion,
                bool $rolelessOperator = false,
            ): void {
                $this->events[] = "recoverable:{$node->name}";
                $completion();
            }
        });

        app(ProvisionNodeAction::class)->execute(new ProvisionNodeData(
            name: 'new-node-path',
            publicSshHost: '192.0.2.94',
            architecture: 'x86_64',
            expectedSshHostFingerprint: 'SHA256:pinned',
        ));

        $existing = Node::query()->create([
            'name' => 'existing-node-path',
            'status' => LifecycleStatus::Provisioning,
            'platform' => 'linux',
            'architecture' => 'x86_64',
            'public_ssh_host' => '192.0.2.95',
            'wireguard_address' => '10.44.0.95',
            'ssh_host_fingerprint' => 'SHA256:pinned',
        ]);
        app(ProvisionNodeAction::class)->execute(new ProvisionNodeData(
            name: $existing->name,
            publicSshHost: $existing->public_ssh_host,
        ));

        $active = Node::query()->create([
            'name' => 'active-node-path',
            'status' => LifecycleStatus::Active,
            'platform' => 'linux',
            'architecture' => 'x86_64',
            'public_ssh_host' => '192.0.2.96',
            'wireguard_address' => '10.44.0.96',
            'ssh_host_fingerprint' => 'SHA256:pinned',
        ]);
        app(ProvisionNodeAction::class)->execute(new ProvisionNodeData(
            name: $active->name,
            publicSshHost: $active->public_ssh_host,
        ));

        expect($events)->toBe([
            'ordinary:new-node-path',
            'ordinary:existing-node-path',
            'recoverable:active-node-path',
        ]);
    });

    it('keeps the prior persisted identity during recoverable completion while using the candidate in memory', function (): void {
        $events = [];
        $materializer = new class($events) implements ToolManagerMaterializer {
            /** @param list<string> $events */
            public function __construct(
                private array &$events,
            ) {}

            public function converge(Node $node, ToolManagerName ...$managerNames): void
            {
                $fresh = Node::query()->whereKey($node->getKey())->sole();
                $this->events[] = "apt:{$node->user}:{$node->status->value}:{$fresh->user}:{$fresh->status->value}";
            }

            public function convergeWithFailureHandler(
                Node $node,
                Closure $onFailure,
                ToolManagerName ...$managerNames,
            ): void {
                $this->converge($node, ...$managerNames);
            }
        };
        app()->instance(ToolManagerMaterializer::class, $materializer);
        app()->instance(RoleBaselineConverger::class, new class($events) implements RoleBaselineConverger {
            /** @param list<string> $events */
            public function __construct(
                private array &$events,
            ) {}

            public function converge(Node $node, NodeRole $assignment): void
            {
                $fresh = Node::query()->whereKey($node->getKey())->sole();
                $this->events[] = "role:{$assignment->role->value}:{$node->user}:{$node->status->value}:{$fresh->user}:{$fresh->status->value}";
            }

            public function remove(Node $node, NodeRole $assignment, bool $purgeData): void {}

            public function removeUnreachable(Node $node, NodeRole $assignment): void {}
        });
        app()->instance(NodeConverger::class, new class($events) implements NodeConverger, RecoverableNodeConverger {
            /** @param list<string> $events */
            public function __construct(
                private array &$events,
            ) {}

            public function converge(
                Node $node,
                NodeProvisioningIdentity $identity,
                ?string $expectedSshHostFingerprint = null,
                bool $rolelessOperator = false,
            ): void {}

            public function convergeRecoverably(
                Node $node,
                NodeProvisioningIdentity $identity,
                ?string $expectedSshHostFingerprint,
                Closure $completion,
                bool $rolelessOperator = false,
            ): void {
                $fresh = Node::query()->whereKey($node->getKey())->sole();
                $this->events[] = "before:{$node->user}:{$node->status->value}:{$fresh->user}:{$fresh->status->value}";
                $completion();
                $fresh = Node::query()->whereKey($node->getKey())->sole();
                $this->events[] = "after:{$node->user}:{$node->status->value}:{$fresh->user}:{$fresh->status->value}";
            }
        });
        $existing = Node::query()->create([
            'name' => 'recoverable-identity',
            'status' => LifecycleStatus::Active,
            'platform' => 'linux',
            'architecture' => 'x86_64',
            'public_ssh_host' => '192.0.2.97',
            'wireguard_address' => '10.44.0.97',
            'user' => 'orbit',
            'ssh_host_fingerprint' => 'SHA256:pinned',
        ]);

        $node = app(ProvisionNodeAction::class)->execute(new ProvisionNodeData(
            name: $existing->name,
            publicSshHost: $existing->public_ssh_host,
            orbitUser: 'nckrtl',
            roles: [RoleName::AppDev, RoleName::Vpn],
            tld: 'recoverable-identity.orbit',
        ));

        expect($events)
            ->toBe([
                'before:orbit:provisioning:orbit:provisioning',
                'apt:nckrtl:provisioning:orbit:provisioning',
                'role:app-dev:nckrtl:provisioning:orbit:provisioning',
                'apt:nckrtl:provisioning:orbit:provisioning',
                'role:vpn:nckrtl:provisioning:orbit:provisioning',
                'after:nckrtl:provisioning:orbit:provisioning',
            ])
            ->and($node->user)
            ->toBe('nckrtl')
            ->and($node->status)
            ->toBe(LifecycleStatus::Active)
            ->and($node->refresh()->user)
            ->toBe('nckrtl');
    });

    it('recovers active state when a requested role fails during completion', function (): void {
        $events = [];
        app()->instance(GatewayPeerProjectionManager::class, new class($events) implements
            GatewayPeerProjectionManager {
            public function __construct(
                private array &$events,
            ) {}

            public function converge(Node $node): void
            {
                $this->events[] = "gateway-converge:{$node->wireguard_public_key}";
            }

            public function remove(Node $node): void
            {
                $this->events[] = 'delete';
            }

            public function restore(Node $node): void
            {
                $this->events[] = "gateway-restore:{$node->wireguard_public_key}";
            }
        });
        app()->instance(NodeConverger::class, new class($events) implements NodeConverger, RecoverableNodeConverger {
            public function __construct(
                private array &$events,
            ) {}

            public function converge(
                Node $node,
                NodeProvisioningIdentity $identity,
                ?string $expectedSshHostFingerprint = null,
                bool $rolelessOperator = false,
            ): void {}

            public function convergeRecoverably(
                Node $node,
                NodeProvisioningIdentity $identity,
                ?string $expectedSshHostFingerprint,
                Closure $completion,
                bool $rolelessOperator = false,
            ): void {
                $node->update(['wireguard_public_key' => 'replacement-key']);
                app(GatewayPeerProjectionManager::class)->converge($node);
                try {
                    $completion();
                } catch (Throwable $throwable) {
                    $this->events[] = 'remote-rollback';
                    throw $throwable;
                }
            }
        });
        app()->instance(RoleBaselineConverger::class, new class implements RoleBaselineConverger {
            public function converge(Node $node, NodeRole $assignment): void
            {
                throw new NodeRoleOperationException(
                    'wireguard',
                    'node.role_failed',
                    'command.failed',
                    'secret raw CommandResult text',
                    new CommandResult(23, 'secret raw CommandResult text', 'secret raw CommandResult text', 1, false),
                );
            }

            public function remove(Node $node, NodeRole $assignment, bool $purgeData): void
            {
                throw new RuntimeException('must not delete');
            }

            public function removeUnreachable(Node $node, NodeRole $assignment): void
            {
                throw new RuntimeException('must not delete');
            }
        });
        $existing = Node::query()->create([
            'name' => 'active-role-failure',
            'status' => LifecycleStatus::Active,
            'platform' => 'linux',
            'architecture' => 'x86_64',
            'public_ssh_host' => '192.0.2.98',
            'wireguard_address' => '10.44.0.98',
            'wireguard_public_key' => 'prior-key',
            'user' => 'orbit',
            'ssh_host_fingerprint' => 'SHA256:pinned',
            'failed_step' => 'prior-step',
            'error_code' => 'prior.error',
        ]);
        expect(fn () => app(ProvisionNodeAction::class)->execute(new ProvisionNodeData(
            name: $existing->name,
            publicSshHost: $existing->public_ssh_host,
            orbitUser: 'nckrtl',
            roles: [RoleName::Vpn],
        )))
            ->toThrow(
                fn (NodeProvisioningException $exception) => (
                    $exception->step === 'role:wireguard'
                    && ! str_contains($exception->getMessage(), 'secret raw CommandResult text')
                ),
            );
        expect($events)
            ->toBe(['gateway-converge:replacement-key', 'remote-rollback', 'gateway-restore:prior-key'])
            ->and($existing->refresh()->only(['status', 'user', 'wireguard_public_key', 'failed_step', 'error_code']))
            ->toBe([
                'status' => LifecycleStatus::Active,
                'user' => 'orbit',
                'wireguard_public_key' => 'prior-key',
                'failed_step' => 'prior-step',
                'error_code' => 'prior.error',
            ])
            ->and($events)
            ->not->toContain('delete');
    });

    it('does not materialize managers after base convergence fails', function (): void {
        $materializer = new FakeToolManagerMaterializer;
        app()->instance(ToolManagerMaterializer::class, $materializer);
        app()->instance(NodeConverger::class, new class implements NodeConverger {
            public function converge(
                Node $node,
                NodeProvisioningIdentity $identity,
                ?string $expectedSshHostFingerprint = null,
                bool $rolelessOperator = false,
            ): void {
                throw new NodeProvisioningException('base-packages', 'node.package_install_failed', 'Base failed.');
            }
        });

        expect(fn () => app(ProvisionNodeAction::class)->execute(new ProvisionNodeData(
            name: 'base-failure',
            publicSshHost: '192.0.2.92',
            architecture: 'x86_64',
            expectedSshHostFingerprint: 'SHA256:pinned',
        )))
            ->toThrow(NodeProvisioningException::class);

        expect($materializer->requests)
            ->toBeEmpty()
            ->and(ToolManagerRecord::query()->count())
            ->toBe(0);
    });

    it('marks the node failed when the APT probe fails', function (): void {
        $materializer = new FakeToolManagerMaterializer;
        $materializer->failure = new NodeProvisioningException(
            'tool-manager-apt',
            'node.tool_manager_probe_failed',
            'Probe failed.',
        );
        app()->instance(ToolManagerMaterializer::class, $materializer);
        app()->instance(NodeConverger::class, new class implements NodeConverger {
            public function converge(
                Node $node,
                NodeProvisioningIdentity $identity,
                ?string $expectedSshHostFingerprint = null,
                bool $rolelessOperator = false,
            ): void {}
        });

        expect(fn () => app(ProvisionNodeAction::class)->execute(new ProvisionNodeData(
            name: 'probe-failure',
            publicSshHost: '192.0.2.93',
            architecture: 'x86_64',
            expectedSshHostFingerprint: 'SHA256:pinned',
        )))
            ->toThrow(NodeProvisioningException::class);

        $node = Node::query()->where('name', 'probe-failure')->sole();
        expect($node->status)
            ->toBe(LifecycleStatus::Failed)
            ->and($node->failed_step)
            ->toBe('tool-manager-apt')
            ->and($node->error_code)
            ->toBe('node.tool_manager_probe_failed');
    });

    it('persists a requested managed user when a new node fails late and uses it on retry', function (): void {
        app()->instance(NodeConverger::class, new class implements NodeConverger {
            public function converge(
                Node $node,
                NodeProvisioningIdentity $identity,
                ?string $expectedSshHostFingerprint = null,
                bool $rolelessOperator = false,
            ): void {
                throw new NodeProvisioningException('late-step', 'node.late_failed', 'Late failure.');
            }
        });

        expect(fn () => app(ProvisionNodeAction::class)->execute(new ProvisionNodeData(
            name: 'new-managed-user',
            publicSshHost: '192.0.2.120',
            architecture: 'x86_64',
            expectedSshHostFingerprint: 'SHA256:pinned',
            orbitUser: 'nckrtl',
        )))
            ->toThrow(NodeProvisioningException::class);

        $node = Node::query()->where('name', 'new-managed-user')->sole();
        expect($node->user)->toBe('nckrtl');

        $identity = null;
        app()->instance(NodeConverger::class, new class($identity) implements NodeConverger {
            public function __construct(
                private mixed &$identity,
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

        app(ProvisionNodeAction::class)->execute(new ProvisionNodeData(
            name: 'new-managed-user',
            publicSshHost: '192.0.2.120',
            expectedSshHostFingerprint: 'SHA256:pinned',
            orbitUser: null,
        ));

        expect($identity)->toBeInstanceOf(NodeProvisioningIdentity::class)->and($identity->managedUser)->toBe('nckrtl');
    });

    it('activates a node after its requested roles converge', function (): void {
        $events = [];
        $converger = new class($events) implements NodeConverger {
            public ?string $expectedFingerprint = null;

            /** @param list<string> $events */
            public function __construct(
                private array &$events,
            ) {}

            public function converge(
                Node $node,
                NodeProvisioningIdentity $identity,
                ?string $expectedSshHostFingerprint = null,
                bool $rolelessOperator = false,
            ): void {
                $this->expectedFingerprint = $expectedSshHostFingerprint;
                $this->events[] = "base:{$node->status->value}:{$node->roles()->count()}";
            }
        };
        app()->instance(NodeConverger::class, $converger);
        app()->instance(RoleBaselineConverger::class, new class($events) implements RoleBaselineConverger {
            /** @param list<string> $events */
            public function __construct(
                private array &$events,
            ) {}

            public function converge(Node $node, NodeRole $assignment): void
            {
                $this->events[] = "role:{$assignment->role->value}:{$node->status->value}:".DB::transactionLevel();
            }

            public function remove(Node $node, NodeRole $assignment, bool $purgeData): void {}

            public function removeUnreachable(Node $node, NodeRole $assignment): void {}
        });

        $ambientTransactionLevel = DB::transactionLevel();
        $node = app(ProvisionNodeAction::class)->execute(new ProvisionNodeData(
            name: 'app-dev',
            publicSshHost: '94.237.40.75',
            roles: [RoleName::AppDev],
            platform: 'linux',
            architecture: 'x86_64',
            tld: '.App-Dev.Orbit',
            expectedSshHostFingerprint: 'SHA256:pinned',
        ));

        expect($node->status)
            ->toBe(LifecycleStatus::Active)
            ->and($node->platform)
            ->toBe('linux')
            ->and($node->architecture)
            ->toBe('x86_64')
            ->and($node->tld)
            ->toBe('app-dev.orbit')
            ->and($node->roles()->sole()->status)
            ->toBe(LifecycleStatus::Active)
            ->and($converger->expectedFingerprint)
            ->toBe('SHA256:pinned')
            ->and($events)
            ->toBe(['base:provisioning:0', "role:app-dev:provisioning:{$ambientTransactionLevel}"]);
    });

    it('rejects pairwise requested role conflicts before persistence or base convergence', function (): void {
        $converger = new class implements NodeConverger {
            public int $calls = 0;

            public function converge(
                Node $node,
                NodeProvisioningIdentity $identity,
                ?string $expectedSshHostFingerprint = null,
                bool $rolelessOperator = false,
            ): void {
                $this->calls++;
            }
        };
        app()->instance(NodeConverger::class, $converger);

        expect(fn () => app(ProvisionNodeAction::class)->execute(new ProvisionNodeData(
            name: 'conflicted',
            publicSshHost: '192.0.2.80',
            roles: [RoleName::AppDev, RoleName::AppProd],
            architecture: 'x86_64',
            tld: 'conflicted.orbit',
            expectedSshHostFingerprint: 'SHA256:pinned',
        )))
            ->toThrow(RoleAssignmentException::class);

        expect($converger->calls)
            ->toBe(0)
            ->and(Node::query()->where('name', 'conflicted')->exists())
            ->toBeFalse();
    });

    it('reconverges requested existing roles and leaves omitted roles untouched', function (): void {
        app()->instance(NodeConverger::class, new class implements NodeConverger {
            public function converge(
                Node $node,
                NodeProvisioningIdentity $identity,
                ?string $expectedSshHostFingerprint = null,
                bool $rolelessOperator = false,
            ): void {}
        });
        $roles = [];
        app()->instance(RoleBaselineConverger::class, new class($roles) implements RoleBaselineConverger {
            /** @param list<RoleName> $roles */
            public function __construct(
                private array &$roles,
            ) {}

            public function converge(Node $node, NodeRole $assignment): void
            {
                $this->roles[] = $assignment->role;
            }

            public function remove(Node $node, NodeRole $assignment, bool $purgeData): void {}

            public function removeUnreachable(Node $node, NodeRole $assignment): void {}
        });
        $node = Node::query()->create([
            'name' => 'existing-host',
            'status' => LifecycleStatus::Active,
            'platform' => 'linux',
            'architecture' => 'x86_64',
            'tld' => 'existing.orbit',
            'public_ssh_host' => '192.0.2.81',
            'wireguard_address' => '10.44.0.8',
            'ssh_host_fingerprint' => 'SHA256:pinned',
        ]);
        $requested = $node->roles()->create(['role' => RoleName::AppDev, 'status' => LifecycleStatus::Active]);
        $omitted = $node->roles()->create([
            'role' => RoleName::Vpn,
            'status' => LifecycleStatus::Failed,
            'failed_step' => 'converge:dnsmasq',
            'error_code' => 'vpn.dnsmasq_failed',
        ]);

        app(ProvisionNodeAction::class)->execute(new ProvisionNodeData(
            name: 'existing-host',
            publicSshHost: '192.0.2.81',
            roles: [RoleName::AppDev],
        ));

        expect($roles)
            ->toBe([RoleName::AppDev])
            ->and($requested->refresh()->status)
            ->toBe(LifecycleStatus::Active)
            ->and($omitted->refresh()->status)
            ->toBe(LifecycleStatus::Failed)
            ->and($omitted->failed_step)
            ->toBe('converge:dnsmasq')
            ->and($omitted->error_code)
            ->toBe('vpn.dnsmasq_failed');
    });

    it('passes the operator expected pin without storing it as the observed fingerprint', function (): void {
        $converger = new class implements NodeConverger {
            public ?string $expectedFingerprint = null;

            public function converge(
                Node $node,
                NodeProvisioningIdentity $identity,
                ?string $expectedSshHostFingerprint = null,
                bool $rolelessOperator = false,
            ): void {
                $this->expectedFingerprint = $expectedSshHostFingerprint;
            }
        };
        app()->instance(NodeConverger::class, $converger);
        $expectedFingerprint = 'SHA256:'.str_repeat(string: 'A', times: 43);

        $node = app(ProvisionNodeAction::class)->execute(new ProvisionNodeData(
            name: 'roleless-operator',
            publicSshHost: '192.0.2.61',
            expectedSshHostFingerprint: $expectedFingerprint,
            architecture: 'x86_64',
        ));

        expect($converger->expectedFingerprint)
            ->toBe($expectedFingerprint)
            ->and($node->ssh_host_fingerprint)
            ->toBeNull();
    });

    it('preserves an existing app-dev TLD when provisioning omits it', function (): void {
        app()->instance(NodeConverger::class, new class implements NodeConverger {
            public function converge(
                Node $node,
                NodeProvisioningIdentity $identity,
                ?string $expectedSshHostFingerprint = null,
                bool $rolelessOperator = false,
            ): void {}
        });
        Node::query()->create([
            'name' => 'app-dev',
            'architecture' => 'x86_64',
            'public_ssh_host' => '94.237.40.75',
            'tld' => 'app-dev.orbit',
            'ssh_host_fingerprint' => 'SHA256:pinned',
        ]);

        $node = app(ProvisionNodeAction::class)->execute(new ProvisionNodeData(
            name: 'app-dev',
            publicSshHost: '94.237.40.75',
            roles: [RoleName::AppDev],
        ));

        expect($node->tld)->toBe('app-dev.orbit');
    });

    it('preserves established node identity and connection fields during safe reprovision', function (): void {
        $materializer = new FakeToolManagerMaterializer;
        app()->instance(ToolManagerMaterializer::class, $materializer);
        $converger = new class implements NodeConverger {
            /** @var array<string, mixed> */
            public array $observed = [];

            public function converge(
                Node $node,
                NodeProvisioningIdentity $identity,
                ?string $expectedSshHostFingerprint = null,
                bool $rolelessOperator = false,
            ): void {
                $this->observed = $node->only([
                    'platform',
                    'architecture',
                    'public_ssh_port',
                    'user',
                    'wireguard_endpoint_override',
                    'dns_server_override',
                ]);
            }
        };
        app()->instance(NodeConverger::class, $converger);
        $existing = Node::query()->create([
            'name' => 'app-dev',
            'status' => LifecycleStatus::Active,
            'platform' => 'linux',
            'architecture' => 'aarch64',
            'tld' => 'app-dev.orbit',
            'public_ssh_host' => '192.0.2.20',
            'public_ssh_port' => 2222,
            'user' => 'orbit',
            'wireguard_address' => '10.44.0.3',
            'wireguard_endpoint_override' => '10.0.0.2:51820',
            'dns_server_override' => '10.0.0.1',
            'ssh_host_fingerprint' => 'SHA256:pinned',
        ]);
        $existing->roles()->create(['role' => RoleName::AppDev, 'status' => LifecycleStatus::Active]);

        $node = app(ProvisionNodeAction::class)->execute(new ProvisionNodeData(
            name: 'app-dev',
            publicSshHost: '192.0.2.20',
        ));

        expect($converger->observed)
            ->toBe([
                'platform' => 'linux',
                'architecture' => 'aarch64',
                'public_ssh_port' => 2222,
                'user' => 'orbit',
                'wireguard_endpoint_override' => '10.0.0.2:51820',
                'dns_server_override' => '10.0.0.1',
            ])
            ->and($node->tld)
            ->toBe('app-dev.orbit')
            ->and($node->roles()->sole()->status)
            ->toBe(LifecycleStatus::Active)
            ->and($materializer->requests)
            ->toBe([[ToolManagerName::Apt]]);
    });

    it('rejects a populated TLD change when the app-dev assignment is not active', function (LifecycleStatus $roleStatus): void {
        $nodeConverger = new class implements NodeConverger {
            public int $calls = 0;

            public function converge(
                Node $node,
                NodeProvisioningIdentity $identity,
                ?string $expectedSshHostFingerprint = null,
                bool $rolelessOperator = false,
            ): void {
                $this->calls++;
            }
        };
        app()->instance(NodeConverger::class, $nodeConverger);
        $node = provision_node_tld_change_record();
        $node->roles()->update(['status' => $roleStatus]);
        $app = App::query()->create([
            'name' => 'Orbit',
            'slug' => 'orbit',
            'repository_url' => 'git@example.test:orbit.git',
        ]);
        $node->instances()->create([
            'app_id' => $app->id,
            'name' => 'main',
            'environment' => 'development',
            'checkout_path' => '/home/orbit/apps/orbit/main',
            'hostname' => 'main.old.orbit',
            'certificate_mode' => 'orbit-ca',
        ]);
        $converger = new class implements AppDevTldConverger {
            public int $calls = 0;

            public function converge(Node $node): void
            {
                $this->calls++;
            }
        };
        app()->instance(AppDevTldConverger::class, $converger);

        expect(fn () => app(ProvisionNodeAction::class)->execute(new ProvisionNodeData(
            name: $node->name,
            publicSshHost: $node->public_ssh_host,
            tld: 'new.orbit',
        )))->toThrow(function (ResourceOperationException $exception): void {
            expect($exception->errorCode)
                ->toBe('node.tld_change_unsupported')
                ->and($exception->status)
                ->toBe(409);
        });

        expect($node->refresh()->tld)->toBe('old.orbit');
        expect($node->instances()->first()->hostname)->toBe('main.old.orbit');
        expect($nodeConverger->calls)->toBe(0);
        expect($converger->calls)->toBe(0);
    })->with([
        'provisioning assignment' => LifecycleStatus::Provisioning,
        'failed assignment' => LifecycleStatus::Failed,
    ]);

    it('converges populated instances when changing an active app-dev TLD', function (): void {
        app()->instance(NodeConverger::class, new class implements NodeConverger {
            public function converge(
                Node $node,
                NodeProvisioningIdentity $identity,
                ?string $expectedSshHostFingerprint = null,
                bool $rolelessOperator = false,
            ): void {}
        });
        $node = Node::query()->create([
            'name' => 'app-dev',
            'status' => LifecycleStatus::Active,
            'platform' => 'linux',
            'architecture' => 'x86_64',
            'tld' => 'app-dev.orbit',
            'public_ssh_host' => '192.0.2.20',
            'wireguard_address' => '10.44.0.3',
            'ssh_host_fingerprint' => 'SHA256:pinned',
        ]);
        $node->roles()->create(['role' => RoleName::AppDev, 'status' => LifecycleStatus::Active]);
        $app = App::query()->create([
            'name' => 'Orbit',
            'slug' => 'orbit',
            'repository_url' => 'git@example.test:orbit.git',
        ]);
        $node->instances()->create([
            'app_id' => $app->id,
            'name' => 'main',
            'environment' => 'development',
            'checkout_path' => '/home/orbit/apps/orbit/main',
            'hostname' => 'main.app-dev.orbit',
            'certificate_mode' => 'orbit-ca',
        ]);

        $tldConverger = new class implements AppDevTldConverger {
            public array $nodes = [];

            public function converge(Node $node): void
            {
                $this->nodes[] = $node->tld;
                $node->instances()->update(['hostname' => "main.{$node->tld}"]);
            }
        };
        app()->instance(AppDevTldConverger::class, $tldConverger);

        $result = app(ProvisionNodeAction::class)->execute(new ProvisionNodeData(
            name: 'app-dev',
            publicSshHost: '192.0.2.20',
            tld: 'changed.orbit',
        ));

        expect($result->tld)
            ->toBe('changed.orbit')
            ->and($node->refresh()->instances()->first()->hostname)
            ->toBe('main.changed.orbit')
            ->and($tldConverger->nodes)
            ->toBe(['changed.orbit']);
    });

    it('converges app development projections before activating a changed TLD', function (): void {
        app()->instance(NodeConverger::class, new class implements NodeConverger {
            public function converge(
                Node $node,
                NodeProvisioningIdentity $identity,
                ?string $expectedSshHostFingerprint = null,
                bool $rolelessOperator = false,
            ): void {}
        });
        $converger = new class implements AppDevTldConverger {
            /** @var list<array{tld: ?string, status: LifecycleStatus}> */
            public array $calls = [];

            public function converge(Node $pendingNode): void
            {
                $this->calls[] = [
                    'tld' => $pendingNode->tld ?? null,
                    'status' => $pendingNode->status ?? LifecycleStatus::Failed,
                ];
            }
        };
        app()->instance(AppDevTldConverger::class, $converger);
        $node = provision_node_tld_change_record();

        $result = app(ProvisionNodeAction::class)->execute(new ProvisionNodeData(
            name: $node->name,
            publicSshHost: $node->public_ssh_host,
            tld: 'new.orbit',
        ));

        expect($result->tld)
            ->toBe('new.orbit')
            ->and($result->status)
            ->toBe(LifecycleStatus::Active)
            ->and($converger->calls)
            ->toBe([['tld' => 'new.orbit', 'status' => LifecycleStatus::Provisioning]]);
    });

    it('restores the previous app development TLD and projections when convergence fails', function (): void {
        app()->instance(NodeConverger::class, new class implements NodeConverger {
            public function converge(
                Node $node,
                NodeProvisioningIdentity $identity,
                ?string $expectedSshHostFingerprint = null,
                bool $rolelessOperator = false,
            ): void {}
        });
        $converger = new ProvisionNodeTldProjectionConverger([1]);
        app()->instance(AppDevTldConverger::class, $converger);
        $node = provision_node_tld_change_record();

        expect(fn () => app(ProvisionNodeAction::class)->execute(new ProvisionNodeData(
            name: $node->name,
            publicSshHost: $node->public_ssh_host,
            tld: 'new.orbit',
        )))->toThrow(function (RuntimeConvergenceException $exception): void {
            expect($exception->errorCode)->toBe('app-dev.dns_config_failed');
        });

        expect($node->refresh()->tld)
            ->toBe('old.orbit')
            ->and($node->status)
            ->toBe(LifecycleStatus::Active)
            ->and($converger->calls)
            ->toBe(['new.orbit', 'old.orbit']);
    });

    it('marks the node failed when restoring previous app development projections fails', function (): void {
        app()->instance(NodeConverger::class, new class implements NodeConverger {
            public function converge(
                Node $node,
                NodeProvisioningIdentity $identity,
                ?string $expectedSshHostFingerprint = null,
                bool $rolelessOperator = false,
            ): void {}
        });
        $converger = new ProvisionNodeTldProjectionConverger([1, 2]);
        app()->instance(AppDevTldConverger::class, $converger);
        $node = provision_node_tld_change_record();

        expect(fn () => app(ProvisionNodeAction::class)->execute(new ProvisionNodeData(
            name: $node->name,
            publicSshHost: $node->public_ssh_host,
            tld: 'new.orbit',
        )))->toThrow(function (RuntimeConvergenceException $exception): void {
            expect($exception->step)
                ->toBe('app-dev-tld-rollback')
                ->and($exception->errorCode)
                ->toBe('app-dev.tld_rollback_failed');
        });

        expect($node->refresh()->tld)
            ->toBe('old.orbit')
            ->and($node->status)
            ->toBe(LifecycleStatus::Failed)
            ->and($node->failed_step)
            ->toBe('app-dev-tld-rollback')
            ->and($node->error_code)
            ->toBe('app-dev.tld_rollback_failed')
            ->and($converger->calls)
            ->toBe(['new.orbit', 'old.orbit']);
    });

    it('rejects a managed user change while the node owns instances', function (): void {
        $converger = new class implements NodeConverger {
            public int $calls = 0;

            public function converge(
                Node $node,
                NodeProvisioningIdentity $identity,
                ?string $expectedSshHostFingerprint = null,
                bool $rolelessOperator = false,
            ): void {
                $this->calls++;
            }
        };
        app()->instance(NodeConverger::class, $converger);
        $node = Node::query()->create([
            'name' => 'app-dev',
            'status' => LifecycleStatus::Active,
            'platform' => 'linux',
            'architecture' => 'x86_64',
            'user' => 'orbit',
            'public_ssh_host' => '192.0.2.20',
            'wireguard_address' => '10.44.0.3',
            'ssh_host_fingerprint' => 'SHA256:pinned',
        ]);
        $app = App::query()->create([
            'name' => 'Orbit',
            'slug' => 'orbit',
            'repository_url' => 'git@example.test:orbit.git',
        ]);
        $node->instances()->create([
            'app_id' => $app->id,
            'name' => 'main',
            'environment' => 'development',
            'checkout_path' => '/home/orbit/apps/orbit/main',
            'hostname' => 'main.app-dev.orbit',
            'certificate_mode' => 'orbit-ca',
        ]);

        expect(fn () => app(ProvisionNodeAction::class)->execute(new ProvisionNodeData(
            name: 'app-dev',
            publicSshHost: '192.0.2.20',
            orbitUser: 'deploy',
        )))->toThrow(function (ResourceOperationException $exception): void {
            expect($exception->errorCode)
                ->toBe('node.user_change_unsupported')
                ->and($exception->status)
                ->toBe(409)
                ->and($exception->getMessage())
                ->toBe('Node [app-dev] cannot change managed user while it owns roles or instances.');
        });

        expect($node->refresh()->user)
            ->toBe('orbit')
            ->and($converger->calls)
            ->toBe(0);
    });

    it('rejects a managed user change while the node owns roles', function (): void {
        $converger = new class implements NodeConverger {
            public int $calls = 0;

            public function converge(
                Node $node,
                NodeProvisioningIdentity $identity,
                ?string $expectedSshHostFingerprint = null,
                bool $rolelessOperator = false,
            ): void {
                $this->calls++;
            }
        };
        app()->instance(NodeConverger::class, $converger);
        $node = Node::query()->create([
            'name' => 'role-node',
            'status' => LifecycleStatus::Active,
            'platform' => 'linux',
            'architecture' => 'x86_64',
            'user' => 'orbit',
            'tld' => 'role-node.orbit',
            'public_ssh_host' => '192.0.2.21',
            'wireguard_address' => '10.44.0.4',
            'ssh_host_fingerprint' => 'SHA256:pinned',
        ]);
        $node->roles()->create([
            'role' => RoleName::AppDev,
            'status' => LifecycleStatus::Active,
        ]);

        expect(fn () => app(ProvisionNodeAction::class)->execute(new ProvisionNodeData(
            name: 'role-node',
            publicSshHost: '192.0.2.21',
            orbitUser: 'deploy',
        )))->toThrow(function (ResourceOperationException $exception): void {
            expect($exception->errorCode)
                ->toBe('node.user_change_unsupported')
                ->and($exception->status)
                ->toBe(409)
                ->and($exception->getMessage())
                ->toBe('Node [role-node] cannot change managed user while it owns roles or instances.');
        });

        expect($node->refresh()->user)
            ->toBe('orbit')
            ->and($converger->calls)
            ->toBe(0);
    });

    it('requires a TLD when the node already owns the app-dev role', function (): void {
        $converger = new class implements NodeConverger {
            public int $calls = 0;

            public function converge(
                Node $node,
                NodeProvisioningIdentity $identity,
                ?string $expectedSshHostFingerprint = null,
                bool $rolelessOperator = false,
            ): void {
                $this->calls++;
            }
        };
        app()->instance(NodeConverger::class, $converger);
        $node = Node::query()->create([
            'name' => 'existing-dev',
            'status' => LifecycleStatus::Active,
            'platform' => 'linux',
            'architecture' => 'x86_64',
            'public_ssh_host' => '192.0.2.30',
            'wireguard_address' => '10.44.0.3',
            'ssh_host_fingerprint' => 'SHA256:pinned',
        ]);
        $node->roles()->create(['role' => RoleName::AppDev, 'status' => LifecycleStatus::Active]);

        expect(fn () => app(ProvisionNodeAction::class)->execute(new ProvisionNodeData(
            name: 'existing-dev',
            publicSshHost: '192.0.2.30',
        )))->toThrow(function (ResourceOperationException $exception): void {
            expect($exception->errorCode)->toBe('node.tld_required');
        });

        expect($converger->calls)->toBe(0);
    });

    it('rejects non-Linux nodes before persistence or convergence', function (): void {
        $converger = new class implements NodeConverger {
            public int $calls = 0;

            public function converge(
                Node $node,
                NodeProvisioningIdentity $identity,
                ?string $expectedSshHostFingerprint = null,
                bool $rolelessOperator = false,
            ): void {
                $this->calls++;
            }
        };
        app()->instance(NodeConverger::class, $converger);

        expect(fn () => app(ProvisionNodeAction::class)->execute(new ProvisionNodeData(
            name: 'mac-dev',
            publicSshHost: '10.44.0.8',
            roles: [RoleName::AppDev],
            platform: 'windows',
            architecture: 'arm64',
            tld: 'mac.test',
        )))->toThrow(function (ResourceOperationException $exception): void {
            expect($exception->errorCode)->toBe('node.platform_unsupported');
        });

        expect(Node::query()->where('name', 'mac-dev')->exists())
            ->toBeFalse()
            ->and($converger->calls)
            ->toBe(0);
    });

    it('requires the real architecture for a new Linux registration', function (): void {
        app()->instance(NodeConverger::class, new class implements NodeConverger {
            public function converge(
                Node $node,
                NodeProvisioningIdentity $identity,
                ?string $expectedSshHostFingerprint = null,
                bool $rolelessOperator = false,
            ): void {}
        });

        expect(fn () => app(ProvisionNodeAction::class)->execute(new ProvisionNodeData(
            name: 'linux-node',
            publicSshHost: '192.0.2.60',
            expectedSshHostFingerprint: 'SHA256:pinned',
        )))->toThrow(function (ResourceOperationException $exception): void {
            expect($exception->errorCode)->toBe('node.architecture_required');
        });

        expect(Node::query()->where('name', 'linux-node')->exists())->toBeFalse();
    });

    it('marks the node failed when initial role convergence fails', function (): void {
        app()->instance(NodeConverger::class, new class implements NodeConverger {
            public function converge(
                Node $node,
                NodeProvisioningIdentity $identity,
                ?string $expectedSshHostFingerprint = null,
                bool $rolelessOperator = false,
            ): void {}
        });
        app()->instance(RoleBaselineConverger::class, new class implements RoleBaselineConverger {
            public function converge(Node $node, NodeRole $assignment): void
            {
                throw new RuntimeConvergenceException(
                    step: 'caddy-config',
                    errorCode: 'app-dev.caddy_config_failed',
                    message: 'Caddy failed.',
                );
            }

            public function remove(Node $node, NodeRole $assignment, bool $purgeData): void {}

            public function removeUnreachable(Node $node, NodeRole $assignment): void {}
        });

        expect(fn () => app(ProvisionNodeAction::class)->execute(new ProvisionNodeData(
            name: 'app-dev',
            publicSshHost: '94.237.40.75',
            roles: [RoleName::AppDev],
            architecture: 'x86_64',
            tld: 'app-dev.orbit',
            expectedSshHostFingerprint: 'SHA256:pinned',
        )))->toThrow(function (NodeProvisioningException $exception): void {
            expect($exception->step)
                ->toBe('role:converge:caddy-config')
                ->and($exception->errorCode)
                ->toBe('node.role_convergence_failed')
                ->and($exception->getMessage())
                ->toBe('Node role provisioning failed.');
        });

        $node = Node::query()->where('name', 'app-dev')->sole();

        expect($node->status)
            ->toBe(LifecycleStatus::Failed)
            ->and($node->roles()->sole()->status)
            ->toBe(LifecycleStatus::Failed)
            ->and($node->failed_step)
            ->toBe('role:converge:caddy-config')
            ->and($node->error_code)
            ->toBe('node.role_convergence_failed')
            ->and($node->roles()->sole()->failed_step)
            ->toBe('converge:caddy-config')
            ->and($node->roles()->sole()->error_code)
            ->toBe('app-dev.caddy_config_failed');
    });

    it('rejects duplicate app-dev TLD ownership before convergence', function (): void {
        $converger = new class implements NodeConverger {
            public int $calls = 0;

            public function converge(
                Node $node,
                NodeProvisioningIdentity $identity,
                ?string $expectedSshHostFingerprint = null,
                bool $rolelessOperator = false,
            ): void {
                $this->calls++;
            }
        };
        app()->instance(NodeConverger::class, $converger);
        Node::query()->create([
            'name' => 'first-dev',
            'public_ssh_host' => '192.0.2.50',
            'tld' => 'test',
        ]);

        expect(fn () => app(ProvisionNodeAction::class)->execute(new ProvisionNodeData(
            name: 'second-dev',
            publicSshHost: '192.0.2.51',
            roles: [RoleName::AppDev],
            architecture: 'x86_64',
            tld: '.TEST',
            expectedSshHostFingerprint: 'SHA256:pinned',
        )))->toThrow(function (ResourceOperationException $exception): void {
            expect($exception->errorCode)
                ->toBe('node.tld_taken')
                ->and($exception->status)
                ->toBe(409);
        });

        expect($converger->calls)->toBe(0);
    });

    it('requires a first-contact fingerprint before persisting a node', function (): void {
        $converger = new class implements NodeConverger {
            public int $calls = 0;

            public function converge(
                Node $node,
                NodeProvisioningIdentity $identity,
                ?string $expectedSshHostFingerprint = null,
                bool $rolelessOperator = false,
            ): void {
                $this->calls++;
            }
        };
        app()->instance(NodeConverger::class, $converger);

        expect(fn () => app(ProvisionNodeAction::class)->execute(new ProvisionNodeData(
            name: 'app-dev',
            publicSshHost: '94.237.40.75',
            roles: [RoleName::AppDev],
            architecture: 'x86_64',
            tld: 'app-dev.orbit',
        )))->toThrow(function (ResourceOperationException $exception): void {
            expect($exception->errorCode)->toBe('node.ssh_host_fingerprint_required');
        });

        expect(Node::query()->where('name', 'app-dev')->exists())
            ->toBeFalse()
            ->and($converger->calls)
            ->toBe(0);
    });

    it('rejects an unsafe WireGuard endpoint override before persisting a node', function (): void {
        app()->instance(NodeConverger::class, new class implements NodeConverger {
            public function converge(
                Node $node,
                NodeProvisioningIdentity $identity,
                ?string $expectedSshHostFingerprint = null,
                bool $rolelessOperator = false,
            ): void {}
        });

        expect(fn () => app(ProvisionNodeAction::class)->execute(new ProvisionNodeData(
            name: 'unsafe-endpoint',
            publicSshHost: '192.0.2.46',
            wireguardEndpointOverride: "10.0.0.2:51820\nPostUp = touch /tmp/orbit-injected",
            expectedSshHostFingerprint: 'SHA256:pinned',
        )))->toThrow(function (ResourceOperationException $exception): void {
            expect($exception->errorCode)->toBe('vpn.endpoint_override_invalid');
        });

        expect(Node::query()->where('name', 'unsafe-endpoint')->exists())->toBeFalse();
    });

    it('stores the failed step and stable error code', function (): void {
        app()->instance(NodeConverger::class, new class implements NodeConverger {
            public function converge(
                Node $node,
                NodeProvisioningIdentity $identity,
                ?string $expectedSshHostFingerprint = null,
                bool $rolelessOperator = false,
            ): void {
                throw new NodeProvisioningException('base-packages', 'node.package_install_failed', 'Apt failed.');
            }
        });
        $action = app(ProvisionNodeAction::class);
        $data = new ProvisionNodeData(
            name: 'app-dev',
            publicSshHost: '94.237.40.75',
            roles: [RoleName::AppDev],
            architecture: 'x86_64',
            tld: 'app-dev.orbit',
            expectedSshHostFingerprint: 'SHA256:pinned',
        );

        expect(fn () => $action->execute($data))->toThrow(NodeProvisioningException::class, 'Apt failed.');

        $node = Node::query()->where('name', 'app-dev')->sole();

        expect($node->status)
            ->toBe(LifecycleStatus::Failed)
            ->and($node->failed_step)
            ->toBe('base-packages')
            ->and($node->error_code)
            ->toBe('node.package_install_failed')
            ->and($node->roles()->exists())
            ->toBeFalse();
    });

    it('passes the bootstrap and managed identities', function (): void {
        $identities = [];
        app()->instance(NodeConverger::class, new class($identities) implements NodeConverger {
            public function __construct(
                private array &$identities,
            ) {}

            public function converge(
                Node $node,
                NodeProvisioningIdentity $identity,
                ?string $expectedSshHostFingerprint = null,
                bool $rolelessOperator = false,
            ): void {
                $this->identities[] = [$identity->bootstrapUser, $identity->managedUser];
            }
        });
        $action = app(ProvisionNodeAction::class);
        $action->execute(new ProvisionNodeData(
            name: 'identity-default',
            publicSshHost: '192.0.2.80',
            architecture: 'x86_64',
            expectedSshHostFingerprint: 'SHA256:pinned',
        ));
        $action->execute(new ProvisionNodeData(
            name: 'identity-explicit',
            publicSshHost: '192.0.2.81',
            architecture: 'x86_64',
            expectedSshHostFingerprint: 'SHA256:pinned',
            user: 'nckrtl',
            orbitUser: 'nckrtl',
        ));
        Node::query()->create([
            'name' => 'identity-existing',
            'status' => LifecycleStatus::Active,
            'platform' => 'linux',
            'architecture' => 'x86_64',
            'public_ssh_host' => '192.0.2.82',
            'wireguard_address' => '10.44.0.82',
            'user' => 'nckrtl',
            'ssh_host_fingerprint' => 'SHA256:pinned',
        ]);
        $action->execute(new ProvisionNodeData(
            name: 'identity-existing',
            publicSshHost: '192.0.2.82',
            architecture: 'x86_64',
            expectedSshHostFingerprint: 'SHA256:pinned',
        ));
        expect($identities)->toBe([['root', 'orbit'], ['nckrtl', 'nckrtl'], ['root', 'nckrtl']]);
    });
});

function provision_node_tld_change_record(): Node
{
    $node = Node::query()->create([
        'name' => 'renamed-app-dev',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'architecture' => 'x86_64',
        'tld' => 'old.orbit',
        'public_ssh_host' => '192.0.2.21',
        'wireguard_address' => '10.44.0.4',
        'ssh_host_fingerprint' => 'SHA256:pinned',
    ]);
    $node->roles()->create(['role' => RoleName::AppDev, 'status' => LifecycleStatus::Active]);

    return $node;
}

/** @mago-expect lint:file-name The stateful fake keeps TLD rollback calls visible in the action test. */
final class ProvisionNodeTldProjectionConverger implements AppDevTldConverger
{
    /** @var list<string|null> */
    public array $calls = [];

    /** @param list<int> $failingCalls */
    public function __construct(
        private readonly array $failingCalls,
    ) {}

    public function converge(Node $pendingNode): void
    {
        $this->calls[] = $pendingNode?->tld;

        if (! in_array(count($this->calls), $this->failingCalls, strict: true)) {
            return;
        }

        throw new RuntimeConvergenceException(
            step: 'app-dev-tld',
            errorCode: 'app-dev.dns_config_failed',
            message: 'DNS failed.',
        );
    }
}
