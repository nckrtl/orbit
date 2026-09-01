<?php

declare(strict_types=1);

use App\Domain\Metrics\ExporterDegradationReason;
use App\Domain\Metrics\ExporterDegradationRepository;
use App\Domain\Metrics\ExporterPreference;
use App\Domain\Metrics\ExporterPreferenceRepository;
use App\Domain\Metrics\ExporterSelector;
use App\Domain\Metrics\MetricsExporterLifecycle;
use App\Domain\Metrics\MetricsRuntimeLifecycle;
use App\Domain\Nodes\RoleAssignmentException;
use App\Infrastructure\Metrics\NativeMetricsExporterProjection;
use App\Infrastructure\Metrics\NativeMetricsStatusReader;
use App\Models\Node;
use App\Models\NodeRole;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('reports disabled state when no metrics assignment exists', function (): void {
    $reader = new NativeMetricsStatusReader(
        new NativeMetricsExporterProjection(new ExporterSelector, app(ExporterPreferenceRepository::class)),
        new StatusRuntimeFake,
        new StatusExporterFake,
        app(ExporterDegradationRepository::class),
    );

    expect($reader->status()->toArray())->toMatchArray(['enabled' => false, 'assignment' => null, 'exporters' => []]);
});

it('fails closed when metrics has multiple assignments', function (): void {
    $first = statusNode('one');
    $second = statusNode('two');
    NodeRole::query()->create(['node_id' => $first->id, 'role' => 'metrics', 'status' => 'active']);
    NodeRole::query()->create(['node_id' => $second->id, 'role' => 'metrics', 'status' => 'active']);
    $reader = new NativeMetricsStatusReader(
        new NativeMetricsExporterProjection(new ExporterSelector, app(ExporterPreferenceRepository::class)),
        new StatusRuntimeFake,
        new StatusExporterFake,
        app(ExporterDegradationRepository::class),
    );

    expect(fn () => $reader->status())->toThrow(RoleAssignmentException::class);
});

it('returns healthy services and deterministic exporter reasons', function (): void {
    $metrics = statusNode('metrics');
    NodeRole::query()->create(['node_id' => $metrics->id, 'role' => 'metrics', 'status' => 'active']);
    $role = statusNode('role-node');
    NodeRole::query()->create(['node_id' => $role->id, 'role' => 'app-dev', 'status' => 'active']);
    $explicitEnabled = statusNode('enabled-node');
    $explicitDisabled = statusNode('disabled-node');
    $roleless = statusNode('roleless-node');
    $preferences = app(ExporterPreferenceRepository::class);
    $preferences->put($explicitEnabled->id, ExporterPreference::Enabled);
    $preferences->put($explicitDisabled->id, ExporterPreference::Disabled);

    $data = new NativeMetricsStatusReader(
        new NativeMetricsExporterProjection(new ExporterSelector, $preferences),
        new StatusRuntimeFake,
        new StatusExporterFake,
        app(ExporterDegradationRepository::class),
    )
        ->status()
        ->toArray();

    expect($data['enabled'])
        ->toBeTrue()
        ->and($data['prometheus'])
        ->toBe('healthy')
        ->and(array_column($data['exporters'], 'name'))
        ->toBe(['disabled-node', 'enabled-node', 'metrics', 'role-node', 'roleless-node'])
        ->and(array_column($data['exporters'], 'reason'))
        ->toBe(['explicit_disabled', 'explicit_enabled', 'metrics_node', 'role_default', 'roleless_default_excluded']);
});

it('selects a node whose role is still provisioning', function (): void {
    $metrics = statusNode('metrics-provisioning');
    NodeRole::query()->create(['node_id' => $metrics->id, 'role' => 'metrics', 'status' => 'active']);
    $provisioning = statusNode('provisioning-node');
    // Exporter convergence already counts this role, so status must agree or it
    // reports a node as excluded while its exporter is being installed.
    NodeRole::query()->create(['node_id' => $provisioning->id, 'role' => 'app-prod', 'status' => 'provisioning']);

    $data = new NativeMetricsStatusReader(
        new NativeMetricsExporterProjection(new ExporterSelector, app(ExporterPreferenceRepository::class)),
        new StatusRuntimeFake,
        new StatusExporterFake,
        app(ExporterDegradationRepository::class),
    )
        ->status()
        ->toArray();

    $exporter = collect($data['exporters'])
        ->firstOrFail(
            static fn (array $row): bool => $row['name'] === 'provisioning-node',
        );

    expect($exporter['desired'])
        ->toBeTrue()
        ->and($exporter['reason'])
        ->toBe('role_default');
});

it('keeps failed Metrics assignments visible', function (): void {
    $metrics = statusNode('metrics-failed');
    NodeRole::query()->create([
        'node_id' => $metrics->id,
        'role' => 'metrics',
        'status' => 'failed',
        'failed_step' => 'prometheus',
        'error_code' => 'metrics.runtime_failed',
    ]);

    $data = new NativeMetricsStatusReader(
        new NativeMetricsExporterProjection(new ExporterSelector, app(ExporterPreferenceRepository::class)),
        new StatusRuntimeFake,
        new StatusExporterFake,
        app(ExporterDegradationRepository::class),
    )
        ->status()
        ->toArray();

    expect($data['enabled'])
        ->toBeTrue()
        ->and($data['assignment']['status'])
        ->toBe('failed')
        ->and($data['assignment']['failed_step'])
        ->toBe('prometheus')
        ->and($data['assignment']['error_code'])
        ->toBe('metrics.runtime_failed');
});

it('reports a skipped node as unknown with its recorded degradation reason', function (): void {
    $metrics = statusNode('metrics-degraded');
    NodeRole::query()->create(['node_id' => $metrics->id, 'role' => 'metrics', 'status' => 'active']);
    $skipped = statusNode('skipped-node');
    NodeRole::query()->create(['node_id' => $skipped->id, 'role' => 'app-prod', 'status' => 'active']);
    $degradations = app(ExporterDegradationRepository::class);
    $degradations->put($skipped->id, ExporterDegradationReason::Unreachable);

    $data = new NativeMetricsStatusReader(
        new NativeMetricsExporterProjection(new ExporterSelector, app(ExporterPreferenceRepository::class)),
        new StatusRuntimeFake,
        new StatusExporterFake,
        $degradations,
    )
        ->status()
        ->toArray();

    $rows = collect($data['exporters'])->keyBy('name');

    // The fake reports every node as active, so an `unknown` row can only come
    // from the recorded skip rather than from a live probe.
    expect($rows['skipped-node'])
        ->toMatchArray([
            'desired' => true,
            'actual' => 'unknown',
            'reason' => 'role_default',
            'degraded_reason' => 'unreachable',
        ])
        ->and($rows['metrics-degraded'])
        ->toMatchArray(['actual' => 'active', 'degraded_reason' => null]);
});

function statusNode(string $name): Node
{
    return Node::query()->create([
        'name' => $name,
        'status' => 'active',
        'platform' => 'linux',
        'public_ssh_host' => '127.0.0.1',
        'ssh_user' => 'orbit',
    ]);
}

final class StatusRuntimeFake implements MetricsRuntimeLifecycle
{
    public function converge(Node $node, NodeRole $assignment): void {}

    public function remove(Node $node, NodeRole $assignment, bool $purgeData): void {}

    public function health(Node $node, string $service): bool
    {
        return true;
    }
}

final class StatusExporterFake implements MetricsExporterLifecycle
{
    public function converge(Node $node, NodeRole $assignment): void {}

    public function remove(Node $node, NodeRole $assignment): void {}

    public function removeNode(Node $node, Node $metricsNode): void {}

    public function actual(Node $node): string
    {
        return 'active';
    }

    public function targets(Node $metricsNode): array
    {
        return [];
    }
}
