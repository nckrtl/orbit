<?php

declare(strict_types=1);

use App\Domain\Metrics\ExporterDegradationReason;
use App\Domain\Metrics\ExporterDegradationRepository;
use App\Domain\Metrics\ExporterPreference;
use App\Domain\Metrics\ExporterPreferenceRepository;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Firewall\UfwRuleOwnership;
use App\Infrastructure\Metrics\MetricsCadvisorRuntime;
use App\Infrastructure\Metrics\MetricsExporterState;
use App\Infrastructure\Metrics\NativeMetricsCadvisorLifecycle;
use App\Infrastructure\Metrics\NativeMetricsExporterProjection;
use App\Models\Node;

it('never inspects or mutates an ineligible node while eligible nodes converge', function (): void {
    $metrics = cadvisorLifecycleNode('metrics', '10.44.0.1');
    $assignment = $metrics->roles()->create([
        'role' => RoleName::Metrics,
        'status' => LifecycleStatus::Active,
    ]);
    $eligible = cadvisorLifecycleNode('eligible', '10.44.0.2');
    $eligible->roles()->create([
        'role' => RoleName::AppDev,
        'status' => LifecycleStatus::Active,
    ]);
    $ineligible = cadvisorLifecycleNode('ineligible', '10.44.0.3');
    $ineligible->update(['ssh_host_fingerprint' => null]);
    app(ExporterPreferenceRepository::class)->put($ineligible->id, ExporterPreference::Enabled);
    $runtime = new CadvisorFleetRuntimeFake('never');

    new NativeMetricsCadvisorLifecycle(
        executor: $runtime,
        projection: app(NativeMetricsExporterProjection::class),
        degradations: app(ExporterDegradationRepository::class),
    )->converge($metrics, $assignment);

    expect($runtime->events)->toBe([
        'snapshot:metrics',
        'snapshot:eligible',
        'converge:metrics',
        'converge:eligible',
    ]);
});

it('restores every earlier cadvisor mutation when a later fleet node fails', function (): void {
    $metrics = cadvisorLifecycleNode('metrics', '10.44.0.3');
    $assignment = $metrics->roles()->create([
        'role' => RoleName::Metrics,
        'status' => LifecycleStatus::Active,
    ]);
    $before = cadvisorLifecycleNode('before', '10.44.0.4');
    $before->roles()->create(['role' => RoleName::AppDev, 'status' => LifecycleStatus::Active]);
    $after = cadvisorLifecycleNode('after', '10.44.0.5');
    $after->roles()->create(['role' => RoleName::AppProd, 'status' => LifecycleStatus::Active]);
    $runtime = new CadvisorFleetRuntimeFake('after');

    expect(fn () => new NativeMetricsCadvisorLifecycle(
        executor: $runtime,
        projection: app(NativeMetricsExporterProjection::class),
        degradations: app(ExporterDegradationRepository::class),
    )->converge($metrics, $assignment))
        ->toThrow(ResourceOperationException::class, 'The later cadvisor failed.');

    expect($runtime->events)->toBe([
        'snapshot:metrics',
        'snapshot:before',
        'snapshot:after',
        'converge:metrics',
        'converge:before',
        'converge:after',
        'restore:after',
        'restore:before',
        'restore:metrics',
    ]);
});

it('skips a fleet node it cannot inspect and records why', function (): void {
    $metrics = cadvisorLifecycleNode('metrics', '10.44.0.3');
    $assignment = $metrics->roles()->create([
        'role' => RoleName::Metrics,
        'status' => LifecycleStatus::Active,
    ]);
    $unreachable = cadvisorLifecycleNode('unreachable', '10.44.0.4');
    $unreachable->roles()->create(['role' => RoleName::AppDev, 'status' => LifecycleStatus::Active]);
    $runtime = new CadvisorFleetRuntimeFake('never', unreachable: 'unreachable');
    $degradations = app(ExporterDegradationRepository::class);

    new NativeMetricsCadvisorLifecycle(
        executor: $runtime,
        projection: app(NativeMetricsExporterProjection::class),
        degradations: $degradations,
    )->converge($metrics, $assignment);

    expect($runtime->events)
        ->toBe(['snapshot:metrics', 'snapshot:unreachable', 'converge:metrics'])
        ->and($degradations->get($unreachable->id))
        ->toBe(ExporterDegradationReason::Unreachable);
});

it('forgets a retired node degradation even when its cadvisor cannot be removed', function (): void {
    $metrics = cadvisorLifecycleNode('metrics', '10.44.0.3');
    $node = cadvisorLifecycleNode('retiring', '10.44.0.4');
    $degradations = app(ExporterDegradationRepository::class);
    $degradations->put($node->id, ExporterDegradationReason::Unreachable);
    $runtime = new class implements MetricsCadvisorRuntime
    {
        public function snapshot(Node $node, Node $metricsNode): MetricsExporterState
        {
            throw new ResourceOperationException('metrics.cadvisor_configuration_inspection_failed', 'unreachable', 502);
        }

        public function converge(Node $node, Node $metricsNode): void {}

        public function remove(Node $node, Node $metricsNode): void
        {
            throw new ResourceOperationException('metrics.cadvisor_configuration_remove_failed', 'cannot remove', 502);
        }

        public function restore(Node $node, Node $metricsNode, MetricsExporterState $state): void {}
    };

    expect(fn () => new NativeMetricsCadvisorLifecycle(
        executor: $runtime,
        projection: app(NativeMetricsExporterProjection::class),
        degradations: $degradations,
    )->removeNode($node, $metrics))
        ->toThrow(ResourceOperationException::class, 'cannot remove');

    expect($degradations->get($node->id))->toBeNull();
});

function cadvisorLifecycleNode(string $name, string $address): Node
{
    return Node::query()->create([
        'name' => $name,
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'architecture' => 'x86_64',
        'public_ssh_host' => '192.0.2.'.(Node::query()->count() + 10),
        'ssh_user' => 'orbit',
        'wireguard_ip' => $address,
        'ssh_host_fingerprint' => 'SHA256:managed',
    ]);
}

final class CadvisorFleetRuntimeFake implements MetricsCadvisorRuntime
{
    /** @var list<string> */
    public array $events = [];

    public function __construct(
        private readonly string $failingNode,
        private readonly ?string $unreachable = null,
    ) {}

    public function snapshot(Node $node, Node $metricsNode): MetricsExporterState
    {
        $this->events[] = "snapshot:{$node->name}";

        if ($node->name === $this->unreachable) {
            throw new ResourceOperationException('metrics.cadvisor_configuration_inspection_failed', 'unreachable', 502);
        }

        return new MetricsExporterState(null, false, UfwRuleOwnership::Missing);
    }

    public function converge(Node $node, Node $metricsNode): void
    {
        $this->events[] = "converge:{$node->name}";

        if ($node->name === $this->failingNode) {
            throw new ResourceOperationException('metrics.cadvisor_failed', 'The later cadvisor failed.', 502);
        }
    }

    public function remove(Node $node, Node $metricsNode): void
    {
        $this->events[] = "remove:{$node->name}";
    }

    public function restore(Node $node, Node $metricsNode, MetricsExporterState $state): void
    {
        $this->events[] = "restore:{$node->name}";
    }
}
