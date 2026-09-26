<?php

declare(strict_types=1);

use App\Domain\AppDev\PrivateDnsManager;
use App\Domain\Broadcasting\RecordBroadcast;
use App\Domain\Broadcasting\RecordEventType;
use App\Domain\Nodes\ManagedUserAccount;
use App\Domain\Nodes\ManagedUserAccountResolver;
use App\Domain\Nodes\NodeConverger;
use App\Domain\Nodes\NodeObservation;
use App\Domain\Nodes\NodeProvisioningIdentity;
use App\Domain\Nodes\RoleBaselineConverger;
use App\Domain\Nodes\Storage\EffectiveStorageRoots;
use App\Domain\Nodes\Storage\NodeStorageRootPreparer;
use App\Domain\Nodes\Storage\StoragePath;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Tools\ToolManagerMaterializer;
use App\Models\Node;
use App\Models\NodeRole;
use Illuminate\Support\Facades\Event;
use Tests\Support\FakeToolManagerMaterializer;

describe('Node record events', function (): void {
    beforeEach(function (): void {
        app()->instance(ToolManagerMaterializer::class, new FakeToolManagerMaterializer);
        app()->instance(RoleBaselineConverger::class, new class implements RoleBaselineConverger
        {
            public function converge(Node $node, NodeRole $assignment): void {}

            public function remove(Node $node, NodeRole $assignment, bool $purgeData): void {}

            public function removeUnreachable(Node $node, NodeRole $assignment): void {}
        });
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
        $operator = Node::query()->create([
            'name' => 'operator',
            'status' => LifecycleStatus::Active,
            'public_ssh_host' => '192.0.2.2',
            'wireguard_ip' => '10.44.0.2',
        ]);
        $this->markAsGateway($operator);
        $this->withServerVariables(['REMOTE_ADDR' => '10.44.0.2']);
    });

    it('broadcasts node.created when a node is provisioned for the first time', function (): void {
        Event::fake([RecordBroadcast::class]);

        $this->postJson('/api/v1/nodes', [
            'name' => 'app-dev',
            'public_ssh_host' => '94.237.40.75',
            'platform' => 'linux',
            'wireguard_ip' => '10.44.0.3',
            'host_key_fingerprint' => 'SHA256:'.str_repeat('A', 43),
        ])->assertCreated();

        $node = Node::query()->where('name', 'app-dev')->sole();

        Event::assertDispatched(
            RecordBroadcast::class,
            fn (RecordBroadcast $event): bool => $event->type === RecordEventType::NodeCreated
                && $event->id === $node->id
                && $event->data['name'] === 'app-dev',
        );
        // Provisioning also broadcasts the command's Activity notices. This asserts the Node event only.
        expect(Event::dispatched(
            RecordBroadcast::class,
            fn (RecordBroadcast $event): bool => str_starts_with($event->type->value, 'node.'),
        ))->toHaveCount(1);
    });

    it('broadcasts node.updated when settings change on an existing node', function (): void {
        app()->instance(NodeStorageRootPreparer::class, new class implements NodeStorageRootPreparer
        {
            public function inspect(Node $node, ManagedUserAccount $account, StoragePath $path): void {}

            public function prepare(Node $node, ManagedUserAccount $account, EffectiveStorageRoots $roots): void {}
        });
        app()->instance(ManagedUserAccountResolver::class, new class implements ManagedUserAccountResolver
        {
            public function resolve(Node $node): ManagedUserAccount
            {
                return new ManagedUserAccount('orbit', 'orbit', '/home/orbit');
            }
        });
        $node = Node::query()->create([
            'name' => 'app-dev',
            'status' => LifecycleStatus::Active,
            'platform' => 'linux',
            'public_ssh_host' => '192.0.2.20',
            'wireguard_ip' => '10.44.0.3',
        ]);

        Event::fake([RecordBroadcast::class]);

        $this->patchJson("/api/v1/nodes/{$node->id}/settings", [
            'apps' => ['path' => '/srv/apps'],
        ])->assertOk();

        Event::assertDispatched(
            RecordBroadcast::class,
            fn (RecordBroadcast $event): bool => $event->type === RecordEventType::NodeUpdated
                && $event->id === $node->id,
        );
    });

    it('broadcasts node.deleted when a node is removed', function (): void {
        app()->instance(PrivateDnsManager::class, new class implements PrivateDnsManager
        {
            public function converge(?Node $pendingNode = null): void {}
        });
        $node = Node::query()->create([
            'name' => 'quarantined',
            'status' => LifecycleStatus::Active,
            'platform' => 'linux',
            'public_ssh_host' => '192.0.2.21',
            'wireguard_ip' => '10.44.0.4',
        ]);

        Event::fake([RecordBroadcast::class]);

        $this->deleteJson("/api/v1/nodes/{$node->id}", ['offline' => false])->assertOk();

        Event::assertDispatched(
            RecordBroadcast::class,
            fn (RecordBroadcast $event): bool => $event->type === RecordEventType::NodeDeleted
                && $event->id === $node->id
                && $event->data === ['id' => $node->id, 'name' => 'quarantined'],
        );
    });
});
