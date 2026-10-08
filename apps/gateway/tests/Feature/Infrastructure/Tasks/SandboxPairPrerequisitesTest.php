<?php

declare(strict_types=1);

use App\Domain\AppDev\PrivateDnsManager;
use App\Domain\Nodes\NodeAgentRuntime;
use App\Infrastructure\Caddy\Build\NodeCaddyBuildResult;
use App\Infrastructure\Caddy\Build\NodeCaddyBuilds;
use App\Infrastructure\Tasks\SandboxPairPrerequisites;
use App\Models\Node;
use App\Models\NodeRole;

use function Pest\Laravel\mock;

/** @return array{Node, Node} */
function prerequisite_pair(): array
{
    $gateway = Node::query()->create(['name' => 'gateway', 'public_ssh_host' => '192.0.2.1', 'status' => 'active', 'platform' => 'linux', 'user' => 'orbit', 'wireguard_ip' => '10.44.0.1']);
    $operator = Node::query()->create(['name' => 'operator', 'public_ssh_host' => '192.0.2.3', 'status' => 'active', 'platform' => 'linux', 'user' => 'orbit', 'wireguard_ip' => '10.44.0.3']);
    foreach (['gateway', 'vpn'] as $role) {
        NodeRole::query()->create(['node_id' => $gateway->id, 'role' => $role, 'status' => 'active']);
    }

    return [$gateway, $operator];
}

it('refreshes both pair Agents and native Gateway projections in order', function (): void {
    [$gateway, $operator] = prerequisite_pair();
    $calls = [];
    mock(NodeAgentRuntime::class)->shouldReceive('converge')->twice()->andReturnUsing(function (Node $node) use (&$calls): void {
        $calls[] = $node->name;
    });
    mock(NodeCaddyBuilds::class)->shouldReceive('build')->once()->withArgs(fn (Node $node): bool => $node->id === $gateway->id)
        ->andReturnUsing(function () use (&$calls): NodeCaddyBuildResult {
            $calls[] = 'caddy';

            return NodeCaddyBuildResult::Published;
        });
    mock(PrivateDnsManager::class)->shouldReceive('converge')->once()->withNoArgs()->andReturnUsing(function () use (&$calls): void {
        $calls[] = 'dns';
    });

    app(SandboxPairPrerequisites::class)->converge(['gateway', 'operator']);

    expect($calls)->toBe(['gateway', 'operator', 'caddy', 'dns']);
    expect($gateway->fresh()->wireguard_ip)->toBe('10.44.0.1');
    expect($operator->fresh()->roles)->toHaveCount(0);
});

it('validates all private identities before any native mutation', function (string $fault): void {
    [$gateway, $operator] = prerequisite_pair();
    match ($fault) {
        'live address' => $gateway->update(['wireguard_ip' => '10.44.0.2']),
        'operator address' => $operator->update(['wireguard_ip' => '10.44.0.20']),
        'user' => $operator->update(['user' => 'nckrtl']),
        'platform' => $operator->update(['platform' => 'macos']),
        'inactive' => $operator->update(['status' => 'failed']),
        'missing vpn' => $gateway->roles()->where('role', 'vpn')->delete(),
        'inactive role' => $gateway->roles()->where('role', 'vpn')->update(['status' => 'failed']),
        'operator role' => NodeRole::query()->create(['node_id' => $operator->id, 'role' => 'app-dev', 'status' => 'active']),
        'extra node' => Node::query()->create(['name' => 'foreign', 'public_ssh_host' => '192.0.2.99', 'status' => 'active']),
        'missing operator' => $operator->delete(),
    };
    mock(NodeAgentRuntime::class)->shouldNotReceive('converge');
    mock(NodeCaddyBuilds::class)->shouldNotReceive('build');
    mock(PrivateDnsManager::class)->shouldNotReceive('converge');

    expect(fn () => app(SandboxPairPrerequisites::class)->converge(['gateway', 'operator']))->toThrow(RuntimeException::class);
})->with(['live address', 'operator address', 'user', 'platform', 'inactive', 'missing vpn', 'inactive role', 'operator role', 'extra node', 'missing operator']);

it('accepts recorded workloads without converging them as pair Nodes', function (): void {
    [$gateway, $operator] = prerequisite_pair();
    $workload = Node::query()->create(['name' => 'app-prod-2', 'public_ssh_host' => '192.0.2.5', 'status' => 'active', 'platform' => 'linux', 'user' => 'orbit', 'wireguard_ip' => '10.44.0.5']);
    NodeRole::query()->create(['node_id' => $workload->id, 'role' => 'app-prod', 'status' => 'active']);
    mock(NodeAgentRuntime::class)->shouldReceive('converge')->twice()->withArgs(fn (Node $node): bool => in_array($node->id, [$gateway->id, $operator->id], true));
    mock(NodeCaddyBuilds::class)->shouldReceive('build')->once()->andReturn(NodeCaddyBuildResult::Unchanged);
    mock(PrivateDnsManager::class)->shouldReceive('converge')->once();

    app(SandboxPairPrerequisites::class)->converge(['gateway', 'operator', 'app-prod-2']);
});

it('stops on native failures so fresh readiness cannot be reported', function (string $step): void {
    prerequisite_pair();
    $agent = mock(NodeAgentRuntime::class);
    $caddy = mock(NodeCaddyBuilds::class);
    $dns = mock(PrivateDnsManager::class);
    if ($step === 'agent') {
        $agent->shouldReceive('converge')->once()->andThrow(new RuntimeException('native failure'));
        $caddy->shouldNotReceive('build');
        $dns->shouldNotReceive('converge');
    } else {
        $agent->shouldReceive('converge')->twice();
        if ($step === 'caddy') {
            $caddy->shouldReceive('build')->once()->andThrow(new RuntimeException('native failure'));
            $dns->shouldNotReceive('converge');
        } else {
            $caddy->shouldReceive('build')->once()->andReturn(NodeCaddyBuildResult::Unchanged);
            $dns->shouldReceive('converge')->once()->andThrow(new RuntimeException('native failure'));
        }
    }

    expect(fn () => app(SandboxPairPrerequisites::class)->converge(['gateway', 'operator']))->toThrow(RuntimeException::class, 'native failure');
})->with(['agent', 'caddy', 'dns']);
