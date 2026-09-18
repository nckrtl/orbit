<?php

declare(strict_types=1);

use App\Domain\Metrics\MetricsCadvisorLifecycle;
use App\Domain\Metrics\MetricsExporterLifecycle;
use App\Domain\Metrics\MetricsRuntimeLifecycle;
use App\Domain\Nodes\RoleAssignmentException;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Metrics\NativeMetricsFleetReconciler;
use App\Models\Node;

it('does nothing while Metrics is disabled', function (): void {
    $exporters = Mockery::mock(MetricsExporterLifecycle::class);
    $cadvisors = Mockery::mock(MetricsCadvisorLifecycle::class);
    $runtime = Mockery::mock(MetricsRuntimeLifecycle::class);
    $exporters->shouldNotReceive('converge');
    $cadvisors->shouldNotReceive('converge');
    $runtime->shouldNotReceive('converge');

    new NativeMetricsFleetReconciler($exporters, $cadvisors, $runtime)->reconcile();
});

it('converges exporters and cadvisors before refreshing the Metrics runtime', function (): void {
    $node = metrics_fleet_node('metrics');
    $assignment = $node->roles()->create([
        'role' => RoleName::Metrics,
        'status' => LifecycleStatus::Active,
    ]);
    $exporters = Mockery::mock(MetricsExporterLifecycle::class);
    $cadvisors = Mockery::mock(MetricsCadvisorLifecycle::class);
    $runtime = Mockery::mock(MetricsRuntimeLifecycle::class);
    $matches = static fn (Node $actualNode, $actualAssignment): bool => (
        $actualNode->is($node) && $actualAssignment->is($assignment)
    );
    $exporters->shouldReceive('converge')->once()->withArgs($matches)->ordered();
    $cadvisors->shouldReceive('converge')->once()->withArgs($matches)->ordered();
    $runtime->shouldReceive('converge')->once()->withArgs($matches)->ordered();

    new NativeMetricsFleetReconciler($exporters, $cadvisors, $runtime)->reconcile();
});

it('retires one node before converging the remaining exporter and cadvisor projection', function (): void {
    $metricsNode = metrics_fleet_node('metrics');
    $assignment = $metricsNode
        ->roles()
        ->create([
            'role' => RoleName::Metrics,
            'status' => LifecycleStatus::Active,
        ]);
    $retiringNode = metrics_fleet_node('retiring');
    $retiringNode->update(['status' => LifecycleStatus::Removing]);
    $exporters = Mockery::mock(MetricsExporterLifecycle::class);
    $cadvisors = Mockery::mock(MetricsCadvisorLifecycle::class);
    $runtime = Mockery::mock(MetricsRuntimeLifecycle::class);
    $removeNodeMatches = static fn (Node $actualNode, Node $actualMetricsNode): bool => (
        $actualNode->is($retiringNode) && $actualMetricsNode->is($metricsNode)
    );
    $convergeMatches = static fn (Node $actualNode, $actualAssignment): bool => (
        $actualNode->is($metricsNode) && $actualAssignment->is($assignment)
    );
    $exporters->shouldReceive('removeNode')->once()->withArgs($removeNodeMatches)->ordered();
    $cadvisors->shouldReceive('removeNode')->once()->withArgs($removeNodeMatches)->ordered();
    $exporters->shouldReceive('converge')->once()->withArgs($convergeMatches)->ordered();
    $cadvisors->shouldReceive('converge')->once()->withArgs($convergeMatches)->ordered();
    $runtime->shouldReceive('converge')->once()->withArgs($convergeMatches)->ordered();

    new NativeMetricsFleetReconciler($exporters, $cadvisors, $runtime)->retire($retiringNode);
});

it('retires an unreachable node without aborting the removal', function (): void {
    $metricsNode = metrics_fleet_node('metrics');
    $assignment = $metricsNode
        ->roles()
        ->create([
            'role' => RoleName::Metrics,
            'status' => LifecycleStatus::Active,
        ]);
    $unreachable = metrics_fleet_node('unreachable');
    $unreachable->update(['status' => LifecycleStatus::Removing]);
    $exporters = Mockery::mock(MetricsExporterLifecycle::class);
    $cadvisors = Mockery::mock(MetricsCadvisorLifecycle::class);
    $runtime = Mockery::mock(MetricsRuntimeLifecycle::class);
    // The node is on its way out of the fleet, so its own exporter and cadvisor teardown are
    // best effort. The remaining projection must still converge.
    $exporters
        ->shouldReceive('removeNode')
        ->once()
        ->andThrow(
            new ResourceOperationException(
                'metrics.exporter_configuration_inspection_failed',
                'The Metrics exporter configuration could not be inspected.',
                502,
            ),
        )
        ->ordered();
    $cadvisors
        ->shouldReceive('removeNode')
        ->once()
        ->andThrow(
            new ResourceOperationException(
                'metrics.cadvisor_configuration_inspection_failed',
                'The cAdvisor configuration could not be inspected.',
                502,
            ),
        )
        ->ordered();
    $exporters
        ->shouldReceive('converge')
        ->once()
        ->withArgs(
            static fn (Node $actualNode, $actualAssignment): bool => (
                $actualNode->is($metricsNode) && $actualAssignment->is($assignment)
            ),
        )
        ->ordered();
    $cadvisors->shouldReceive('converge')->once()->ordered();
    $runtime->shouldReceive('converge')->once()->ordered();

    new NativeMetricsFleetReconciler($exporters, $cadvisors, $runtime)->retire($unreachable);
});

it('fails closed when active Metrics assignments drift', function (): void {
    foreach (['metrics-a', 'metrics-b'] as $name) {
        metrics_fleet_node($name)->roles()->create([
            'role' => RoleName::Metrics,
            'status' => LifecycleStatus::Active,
        ]);
    }

    $reconciler = new NativeMetricsFleetReconciler(
        Mockery::mock(MetricsExporterLifecycle::class),
        Mockery::mock(MetricsCadvisorLifecycle::class),
        Mockery::mock(MetricsRuntimeLifecycle::class),
    );

    expect($reconciler->reconcile(...))
        ->toThrow(RoleAssignmentException::class, 'Active Metrics role assignment drift detected.');
});

function metrics_fleet_node(string $name): Node
{
    return Node::query()->create([
        'name' => $name,
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'architecture' => 'x86_64',
        'public_ssh_host' => '192.0.2.'.(Node::query()->count() + 10),
        'wireguard_ip' => '10.44.0.'.(Node::query()->count() + 10),
    ]);
}
