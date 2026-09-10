<?php

declare(strict_types=1);

use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Models\Node;

it('admits the active Gateway and a peer with directed Gateway access', function (): void {
    $gateway = $this->markAsGateway(grafanaAuthorizationNode('gateway', '10.44.0.1'));
    $granted = grafanaAuthorizationNode('granted', '10.44.0.2');
    $granted->accessibleNodes()->attach($gateway);

    foreach ([$gateway, $granted] as $caller) {
        $this
            ->withServerVariables(['REMOTE_ADDR' => $caller->wireguard_ip])
            ->get('/api/v1/metrics/grafana/authorize')
            ->assertNoContent();
    }
});

it('denies callers without active Gateway authority', function (): void {
    $gateway = $this->markAsGateway(grafanaAuthorizationNode('gateway', '10.44.0.1'));
    $metrics = grafanaAuthorizationNode('metrics', '10.44.0.3');
    $metrics->roles()->create(['role' => RoleName::Metrics, 'status' => LifecycleStatus::Active]);
    $metricsOnly = grafanaAuthorizationNode('metrics-only', '10.44.0.4');
    $metricsOnly->accessibleNodes()->attach($metrics);
    $ungranted = grafanaAuthorizationNode('ungranted', '10.44.0.5');
    $inactive = grafanaAuthorizationNode('inactive', '10.44.0.6', LifecycleStatus::Failed);

    foreach ([$metricsOnly, $ungranted, $inactive] as $caller) {
        $this
            ->withServerVariables(['REMOTE_ADDR' => $caller->wireguard_ip])
            ->getJson('/api/v1/metrics/grafana/authorize')
            ->assertForbidden();
    }

    $this
        ->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])
        ->getJson('/api/v1/metrics/grafana/authorize')
        ->assertForbidden()
        ->assertJsonPath('error.code', 'peer.identity_unknown');

    expect($gateway->exists)->toBeTrue();
});

it('trusts only the connection address and fails closed without one active Gateway', function (): void {
    $gateway = $this->markAsGateway(grafanaAuthorizationNode('gateway', '10.44.0.1'));
    $caller = grafanaAuthorizationNode('caller', '10.44.0.2');
    $caller->accessibleNodes()->attach($gateway);

    $this
        ->withServerVariables(['REMOTE_ADDR' => '10.44.0.99'])
        ->withHeaders([
            'Forwarded' => 'for=10.44.0.2',
            'X-Forwarded-For' => '10.44.0.2',
            'X-Orbit-Node-Id' => (string) $caller->id,
        ])
        ->getJson('/api/v1/metrics/grafana/authorize')
        ->assertForbidden()
        ->assertJsonPath('error.code', 'peer.identity_unknown');

    $gateway->roles()->update(['status' => LifecycleStatus::Failed]);

    $this
        ->withServerVariables(['REMOTE_ADDR' => $caller->wireguard_ip])
        ->getJson('/api/v1/metrics/grafana/authorize')
        ->assertForbidden()
        ->assertJsonPath('error.code', 'node_access.required');
});

function grafanaAuthorizationNode(
    string $name,
    string $address,
    LifecycleStatus $status = LifecycleStatus::Active,
): Node {
    return Node::query()->create([
        'name' => $name,
        'status' => $status,
        'platform' => 'linux',
        'public_ssh_host' => $name.'.example.test',
        'user' => 'orbit',
        'wireguard_ip' => $address,
    ]);
}
