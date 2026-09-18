<?php

declare(strict_types=1);

use App\Domain\Herdr\HerdrSessionManagement;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Infrastructure\Nodes\NativeNodeProvisioningLock;
use App\Models\Activity;
use App\Models\HerdrSession;
use App\Models\Node;
use Illuminate\Support\Str;

describe('PATCH /api/v1/nodes/{node}/name', function (): void {
    beforeEach(function (): void {
        $this->operator = $this->markAsGateway(Node::query()->create([
            'name' => 'operator',
            'status' => LifecycleStatus::Active,
            'public_ssh_host' => '192.0.2.2',
            'wireguard_ip' => '10.44.0.2',
        ]));
        $this->withServerVariables(['REMOTE_ADDR' => '10.44.0.2']);
    });

    it('renames a node and leaves wireguard identity and roles in place', function (): void {
        $node = Node::query()->create([
            'name' => 'gateway',
            'status' => LifecycleStatus::Active,
            'public_ssh_host' => '192.0.2.10',
            'user' => 'orbit',
            'wireguard_ip' => '10.44.0.1',
            'wireguard_public_key' => 'wg-gateway-public',
        ]);
        $node->roles()->create([
            'role' => RoleName::Gateway,
            'status' => LifecycleStatus::Active,
        ]);
        $node->roles()->create([
            'role' => RoleName::Vpn,
            'status' => LifecycleStatus::Active,
        ]);
        $requestId = (string) Str::uuid();

        $this
            ->withHeader('X-Orbit-Request-Id', $requestId)
            ->patchJson("/api/v1/nodes/{$node->id}/name", ['name' => 'vpn'])
            ->assertOk()
            ->assertJsonPath('data.id', $node->id)
            ->assertJsonPath('data.name', 'vpn')
            ->assertJsonPath('data.wireguard_ip', '10.44.0.1')
            ->assertJsonPath('data.wireguard_public_key', 'wg-gateway-public')
            ->assertJsonPath('data.roles', ['gateway', 'vpn'])
            ->assertJsonPath('meta.request_id', $requestId);

        expect($node->refresh())
            ->name->toBe('vpn')
            ->wireguard_ip->toBe('10.44.0.1')
            ->wireguard_public_key->toBe('wg-gateway-public')
            ->and($node->roles()->pluck('role')->map(static fn (RoleName $role): string => $role->value)->sort()->values()->all())
            ->toBe(['gateway', 'vpn']);

        $activity = Activity::query()->where('request_id', $requestId)->sole();

        expect($activity)
            ->command->toBe('node:rename')
            ->subject_type->toBe(Node::class)
            ->subject_id->toBe($node->id)
            ->target_node_id->toBe($node->id)
            ->status->toBe('succeeded')
            ->and($activity->properties?->get('input'))
            ->toBe(['name' => 'vpn']);
    });

    it('treats the current name as a successful no-op', function (): void {
        $node = Node::query()->create([
            'name' => 'vpn',
            'status' => LifecycleStatus::Active,
            'public_ssh_host' => '192.0.2.10',
            'wireguard_ip' => '10.44.0.1',
        ]);

        $this
            ->patchJson("/api/v1/nodes/{$node->id}/name", ['name' => 'vpn'])
            ->assertOk()
            ->assertJsonPath('data.name', 'vpn');

        expect($node->refresh()->name)->toBe('vpn');
    });

    it('refuses a name another node already holds', function (): void {
        $node = Node::query()->create([
            'name' => 'gateway',
            'status' => LifecycleStatus::Active,
            'public_ssh_host' => '192.0.2.10',
            'wireguard_ip' => '10.44.0.1',
        ]);
        Node::query()->create([
            'name' => 'vpn',
            'status' => LifecycleStatus::Active,
            'public_ssh_host' => '192.0.2.11',
            'wireguard_ip' => '10.44.0.3',
        ]);

        $this
            ->patchJson("/api/v1/nodes/{$node->id}/name", ['name' => 'vpn'])
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'validation.failed');

        expect($node->refresh()->name)->toBe('gateway');
    });

    it('refuses malformed names without mutation', function (): void {
        $node = Node::query()->create([
            'name' => 'gateway',
            'status' => LifecycleStatus::Active,
            'public_ssh_host' => '192.0.2.10',
            'wireguard_ip' => '10.44.0.1',
        ]);

        $this
            ->patchJson("/api/v1/nodes/{$node->id}/name", ['name' => 'not valid'])
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'validation.failed');

        expect($node->refresh()->name)->toBe('gateway');
    });

    it('refuses rename while the node owns a herdr session', function (): void {
        $node = Node::query()->create([
            'name' => 'gateway',
            'status' => LifecycleStatus::Active,
            'public_ssh_host' => '192.0.2.10',
            'user' => 'orbit',
            'wireguard_ip' => '10.44.0.1',
        ]);
        HerdrSession::query()->create([
            'node_id' => $node->id,
            'session' => 'commander-tasks',
            'user' => 'orbit',
            'observer_port' => 7411,
            'observer_hostname' => 'commander-tasks.herdr.gateway.orbit',
            'observer_status' => 'pending',
            'status' => LifecycleStatus::Active,
            'management' => HerdrSessionManagement::Managed,
            'publish_observer' => false,
        ]);

        $this
            ->patchJson("/api/v1/nodes/{$node->id}/name", ['name' => 'vpn'])
            ->assertConflict()
            ->assertJsonPath('error.code', 'node.has_herdr_sessions');

        expect($node->refresh()->name)->toBe('gateway');
    });

    it('refuses rename while another lifecycle owner holds the current or destination name', function (): void {
        $node = Node::query()->create([
            'name' => 'gateway',
            'status' => LifecycleStatus::Active,
            'public_ssh_host' => '192.0.2.10',
            'wireguard_ip' => '10.44.0.1',
        ]);
        $holder = new NativeNodeProvisioningLock;

        $holder->run('gateway', function () use ($node): void {
            $this
                ->patchJson("/api/v1/nodes/{$node->id}/name", ['name' => 'vpn'])
                ->assertConflict()
                ->assertJsonPath('error.code', 'node.provisioning_busy');
        });

        $holder->run('vpn', function () use ($node): void {
            $this
                ->patchJson("/api/v1/nodes/{$node->id}/name", ['name' => 'vpn'])
                ->assertConflict()
                ->assertJsonPath('error.code', 'node.provisioning_busy');
        });

        expect($node->refresh()->name)->toBe('gateway');
    });
});
