<?php

declare(strict_types=1);

use App\Domain\Gateway\GatewayWebConverger;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\WireGuard\VpnSettings;
use App\Models\Node;

function recordingGatewayWebConverger(): GatewayWebConverger
{
    $converger = new class implements GatewayWebConverger
    {
        /** @var list<array{string, string}> */
        public array $calls = [];

        public function converge(string $hostname, string $wireguardIp): void
        {
            $this->calls[] = [$hostname, $wireguardIp];
        }
    };
    app()->instance(GatewayWebConverger::class, $converger);

    return $converger;
}

function gatewayWebNode(LifecycleStatus $roleStatus = LifecycleStatus::Active): Node
{
    $node = Node::query()->create([
        'name' => 'gateway',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.2',
        'user' => 'orbit',
        'wireguard_ip' => '10.44.0.2',
    ]);
    $node->roles()->create(['role' => RoleName::Gateway, 'status' => $roleStatus]);

    return $node;
}

it('publishes the site for the active Gateway Node under the stored private domain', function (): void {
    $converger = recordingGatewayWebConverger();
    $node = gatewayWebNode();
    app(VpnSettings::class)->configure(subnet: '10.44.0.0/24', domain: 'fleet');

    $this->artisan('orbit:gateway-web')
        ->expectsOutput('Gateway [gateway] site is published.')
        ->assertExitCode(0);

    expect($converger->calls)->toBe([['gateway.fleet', '10.44.0.2']])
        ->and($node->refresh()->status)->toBe(LifecycleStatus::Active);
});

it('refuses without an active Gateway Node before publishing anything', function (): void {
    $converger = recordingGatewayWebConverger();
    gatewayWebNode(LifecycleStatus::Provisioning);

    $this->artisan('orbit:gateway-web')
        ->expectsOutput('Gateway web convergence failed at step [gateway-web-lookup] with error [gateway.web_node_missing].')
        ->assertExitCode(1);

    expect($converger->calls)->toBe([]);
});
