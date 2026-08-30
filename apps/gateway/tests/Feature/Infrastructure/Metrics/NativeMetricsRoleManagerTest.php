<?php

declare(strict_types=1);

use App\Domain\Metrics\MetricsPublicationCleanup;
use App\Domain\Nodes\RoleAssignmentException;
use App\Domain\Nodes\RoleBaselineConverger;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Infrastructure\Metrics\NativeMetricsRoleManager;
use App\Models\Node;
use App\Models\NodeRole;

it('fails closed before removal when Metrics assignments drift', function (): void {
    foreach (['metrics-a', 'metrics-b'] as $name) {
        $node = Node::query()->create([
            'name' => $name,
            'status' => LifecycleStatus::Active,
            'platform' => 'linux',
            'architecture' => 'x86_64',
            'public_ssh_host' => '192.0.2.'.(Node::query()->count() + 20),
            'wireguard_address' => '10.44.0.'.(Node::query()->count() + 20),
        ]);
        $node->roles()->create([
            'role' => RoleName::Metrics,
            'status' => LifecycleStatus::Active,
        ]);
    }

    expect(fn () => app(NativeMetricsRoleManager::class)->remove(true, false))
        ->toThrow(RoleAssignmentException::class, 'Metrics role assignment drift detected.');
});

it('reports the publication as cleaned when one active Gateway removes Metrics', function (): void {
    $metrics = metricsRoleManagerNode('metrics', '10.44.0.3');
    metricsRoleManagerNode('gateway', '10.44.0.1', RoleName::Gateway);
    metricsRoleManagerStubBaselines();

    $result = app(NativeMetricsRoleManager::class)->remove(true, false);

    expect($result->nodeId)
        ->toBe($metrics->id)
        ->and($result->status)
        ->toBe('removed')
        ->and($result->publication)
        ->toBe(MetricsPublicationCleanup::Cleaned)
        ->and($result->toArray()['publication'])
        ->toBe('cleaned');
});

it('reports the publication as un-cleaned when no single active Gateway remains', function (
    int $gatewayCount,
): void {
    $metrics = metricsRoleManagerNode('metrics', '10.44.0.3');

    for ($index = 1; $index <= $gatewayCount; $index++) {
        metricsRoleManagerNode("gateway-{$index}", "10.44.0.{$index}", RoleName::Gateway);
    }
    metricsRoleManagerStubBaselines();

    $result = app(NativeMetricsRoleManager::class)->remove(true, false);

    expect($result->nodeId)
        ->toBe($metrics->id)
        ->and($result->status)
        ->toBe('removed')
        ->and($result->publication)
        ->toBe(MetricsPublicationCleanup::Uncleaned)
        ->and($result->toArray()['publication'])
        ->toBe('uncleaned');
})->with([0, 2]);

function metricsRoleManagerNode(string $name, string $address, RoleName $role = RoleName::Metrics): Node
{
    $node = Node::query()->create([
        'name' => $name,
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'architecture' => 'x86_64',
        'public_ssh_host' => str_replace('10.44', '192.0.2', $address),
        'wireguard_address' => $address,
    ]);
    $node->roles()->create([
        'role' => $role,
        'status' => LifecycleStatus::Active,
    ]);

    return $node;
}

function metricsRoleManagerStubBaselines(): void
{
    app()->instance(RoleBaselineConverger::class, new class implements RoleBaselineConverger {
        public function converge(Node $node, NodeRole $assignment): void {}

        public function remove(Node $node, NodeRole $assignment, bool $purgeData): void {}
    });
}
