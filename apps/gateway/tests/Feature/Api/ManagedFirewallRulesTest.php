<?php

declare(strict_types=1);

use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Models\Node;
use App\Models\NodeRole;

beforeEach(function (): void {
    $this->node = Node::query()->create([
        'name' => 'edge',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.20',
        'public_ssh_port' => 2222,
        'user' => 'orbit',
        'wireguard_ip' => '10.44.0.3',
    ]);
    $this->node->accessibleNodes()->attach($this->node);
    $this->withServerVariables(['REMOTE_ADDR' => $this->node->wireguard_ip]);
    $this->url = "/api/v1/nodes/{$this->node->id}/managed-firewall-rules";
});

describe('firewall:managed:list', function (): void {
    it('lists the rules every managed Node has, for a Node without a role', function (): void {
        $this->getJson($this->url)->assertOk()->assertJsonPath('data', [
            [
                'name' => 'orbit:public-ssh-recovery',
                'role' => null,
                'action' => 'allow',
                'source' => 'any',
                'destination' => 'any',
                'port' => '2222',
                'protocol' => 'tcp',
                'interface' => null,
            ],
            [
                'name' => 'orbit:wireguard-members',
                'role' => null,
                'action' => 'allow',
                'source' => 'any',
                'destination' => '10.44.0.3',
                'port' => 'any',
                'protocol' => 'any',
                'interface' => 'orbit',
            ],
        ]);
    });

    it('adds the rules of each active role and names the role', function (): void {
        NodeRole::query()->create(['node_id' => $this->node->id, 'role' => RoleName::Gateway, 'status' => LifecycleStatus::Active]);
        NodeRole::query()->create(['node_id' => $this->node->id, 'role' => RoleName::WebSocket, 'status' => LifecycleStatus::Provisioning]);

        $rules = $this->getJson($this->url)->assertOk()->json('data');

        expect(array_column($rules, 'name'))
            ->toBe(['orbit:public-ssh-recovery', 'orbit:wireguard-members', 'orbit:gateway-https'])
            ->and($rules[2])->toMatchArray(['role' => 'gateway', 'port' => '443', 'interface' => 'orbit']);
    });

    it('lists nothing for a Node that has no WireGuard address yet', function (): void {
        $this->node->update(['wireguard_ip' => null]);
        $caller = Node::query()->create([
            'name' => 'caller', 'status' => LifecycleStatus::Active, 'platform' => 'linux',
            'public_ssh_host' => '192.0.2.21', 'public_ssh_port' => 22, 'user' => 'orbit', 'wireguard_ip' => '10.44.0.9',
        ]);
        $caller->accessibleNodes()->attach($this->node);

        $this->withServerVariables(['REMOTE_ADDR' => '10.44.0.9'])
            ->getJson($this->url)->assertOk()->assertJsonPath('data', []);
    });

    it('offers no route that changes a managed rule', function (): void {
        $routes = collect(app('router')->getRoutes()->getRoutes())
            ->filter(static fn ($route): bool => str_contains($route->uri(), 'managed-firewall-rules'));

        expect($routes)->toHaveCount(1)
            ->and($routes->first()->methods())->toBe(['GET', 'HEAD']);
    });
});
